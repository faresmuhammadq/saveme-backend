<?php

use App\Modules\Billing\Application\GooglePlay\Jobs\ProcessPlayNotification;
use App\Modules\Billing\Infrastructure\Models\PlayNotification;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\PlayPushSigner;

/*
 * US4: the webhook stores a verified push and answers 204; anything else
 * writes nothing (spec 024, FR-030 to FR-035). Google sends no client headers.
 */

beforeEach(function () {
    $this->signer = PlayPushSigner::make();
    $this->signer->serveCertificates();
    $this->token = playToken('hook');
    Queue::fake();
});

function signedPush(array $push): TestResponse
{
    return postPush($push, test()->signer->token());
}

test('a verified push is stored in the inbox with its token sealed, and processing is queued; the answer is 204', function () {
    $response = signedPush(pubsubPush(subscriptionNotification($this->token), 'msg-1001'));

    $response->assertNoContent();
    $row = PlayNotification::query()->sole();
    expect($row->message_id)->toBe('msg-1001')->and($row->kind)->toBe('subscription')->and($row->state)->toBe('received')
        ->and($row->notification_type)->toBe(2)->and($row->product_id)->toBe('plus_monthly')
        ->and($row->token_lookup)->toBe(playLookup($this->token))
        ->and($row->token_sealed)->not->toBeNull()->not->toContain($this->token)
        ->and($row->event_at)->not->toBeNull()->and($row->published_at)->not->toBeNull();
    Queue::assertPushed(ProcessPlayNotification::class, fn (ProcessPlayNotification $job) => $job->notificationId === $row->id && $job->queue === 'billing');
});

test('the raw body is never stored: no column holds the data member or the token', function () {
    signedPush(pubsubPush(subscriptionNotification($this->token), 'msg-1002'))->assertNoContent();

    $dump = json_encode(PlayNotification::query()->sole()->getAttributes());
    expect($dump)->not->toContain($this->token)->not->toContain('purchaseToken');
});

test('a repeated message id is 204 with no effect: one row, one job', function () {
    $push = pubsubPush(subscriptionNotification($this->token), 'msg-1003');

    signedPush($push)->assertNoContent();
    signedPush($push)->assertNoContent();
    signedPush($push)->assertNoContent();

    expect(PlayNotification::query()->count())->toBe(1);
    Queue::assertPushed(ProcessPlayNotification::class, 1);
});

test('a push for another Pub/Sub subscription is stored as ignored and answered 204, with no job', function () {
    signedPush(pubsubPush(subscriptionNotification($this->token), 'msg-1004', 'projects/other/subscriptions/else'))->assertNoContent();

    $row = PlayNotification::query()->sole();
    expect($row->state)->toBe('ignored')->and($row->ignored_reason)->toBe('foreign_subscription')->and($row->token_sealed)->toBeNull();
    Queue::assertNothingPushed();
});

test('a push for another package is stored as ignored', function () {
    signedPush(pubsubPush([...subscriptionNotification($this->token), 'packageName' => 'com.other.app'], 'msg-1005'))->assertNoContent();

    expect(PlayNotification::query()->sole()->ignored_reason)->toBe('foreign_package');
    Queue::assertNothingPushed();
});

test('a test notification is stored as ignored', function () {
    signedPush(pubsubPush(['testNotification' => ['version' => '1.0']], 'msg-1006'))->assertNoContent();

    $row = PlayNotification::query()->sole();
    expect($row->kind)->toBe('test')->and($row->ignored_reason)->toBe('test');
    Queue::assertNothingPushed();
});

test('a verified push that cannot be read is acknowledged as ignored so Pub/Sub stops retrying it', function (string $data) {
    $push = ['message' => ['data' => $data, 'messageId' => 'msg-1007'], 'subscription' => config('billing.google_play.push.subscription')];

    signedPush($push)->assertNoContent();

    $row = PlayNotification::query()->sole();
    expect($row->state)->toBe('ignored')->and($row->ignored_reason)->toBe('malformed')->and($row->kind)->toBe('malformed');
    Queue::assertNothingPushed();
})->with([
    'not base64' => ['@@@@'],
    'base64 of not json' => [base64_encode('nonsense')],
    'json of no notification' => [base64_encode('{"packageName":"com.example.app"}')],
]);

test('a voided purchase notification is stored received and queued', function () {
    signedPush(pubsubPush(['voidedPurchaseNotification' => ['purchaseToken' => $this->token, 'orderId' => 'GPA.1', 'productType' => 1, 'refundType' => 1]], 'msg-1008'))->assertNoContent();

    expect(PlayNotification::query()->sole()->kind)->toBe('voided');
    Queue::assertPushed(ProcessPlayNotification::class, 1);
});

test('an unsigned or wrongly signed push is 401 and nothing is stored or queued', function (?string $bearer) {
    postPush(pubsubPush(subscriptionNotification($this->token), 'msg-1009'), $bearer)->assertStatus(401)->assertJsonPath('code', 'PROVIDER_SIGNATURE_INVALID');

    expect(PlayNotification::query()->count())->toBe(0)->and(PlayPurchase::query()->count())->toBe(0)->and(Subscription::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'no bearer' => [null],
    'garbage' => ['not.a.jwt'],
]);

test('a forged signature, a wrong audience and a stale token are each 401', function () {
    $push = pubsubPush(subscriptionNotification($this->token), 'msg-1010');

    postPush($push, $this->signer->forgedToken())->assertStatus(401);
    postPush($push, $this->signer->token(['aud' => 'https://other.example']))->assertStatus(401);
    postPush($push, $this->signer->token(['iat' => time() - 400]))->assertStatus(401);
    postPush($push, $this->signer->token(['email_verified' => false]))->assertStatus(401);

    expect(PlayNotification::query()->count())->toBe(0);
});

test('a refusal is counted by reason in an hourly counter and logged by reason only, never the token', function () {
    Log::spy();

    postPush(pubsubPush(subscriptionNotification($this->token)), $this->signer->token(['aud' => 'https://other.example']))->assertStatus(401);

    expect(Cache::get('billing:play:rejected:audience:'.now()->utc()->format('YmdH')))->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'billing_play_push_rejected' && $context === ['reason' => 'audience'])->once();
});

test('a body over 64 KiB is 400 MALFORMED_JSON before anything is written or verified', function () {
    $push = pubsubPush(subscriptionNotification($this->token));
    $push['message']['attributes'] = ['padding' => str_repeat('x', 70000)];

    postPush($push, $this->signer->token())->assertStatus(400)->assertJsonPath('code', 'MALFORMED_JSON');

    expect(PlayNotification::query()->count())->toBe(0);
});

test('a body that is not the Pub/Sub shape is 400 MALFORMED_JSON', function (array $body) {
    $this->postJson('/api/v1/webhooks/google-play', $body, ['Authorization' => 'Bearer '.$this->signer->token()])->assertStatus(400)->assertJsonPath('code', 'MALFORMED_JSON');
})->with([
    'no message' => [['subscription' => 's']],
    'no data' => [['message' => ['messageId' => '1'], 'subscription' => 's']],
    'no message id' => [['message' => ['data' => 'e30='], 'subscription' => 's']],
    'no subscription' => [['message' => ['data' => 'e30=', 'messageId' => '1']]],
    'a message id too long' => [['message' => ['data' => 'e30=', 'messageId' => str_repeat('1', 129)], 'subscription' => 's']],
]);

test('a body that is not JSON is refused before verification', function () {
    $this->call('POST', '/api/v1/webhooks/google-play', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->signer->token()], '{not json')
        ->assertStatus(400)->assertJsonPath('code', 'MALFORMED_JSON');
});

test('when Google\'s certificates cannot be obtained the push is 503 and nothing is stored', function () {
    Http::swap(new Factory);
    Http::fake([PlayPushSigner::CERTS_URL => Http::response('', 500)]);

    $response = signedPush(pubsubPush(subscriptionNotification($this->token), 'msg-1011'))->assertStatus(503);

    expect($response->json('code'))->toBe('SERVICE_UNAVAILABLE')->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and(PlayNotification::query()->count())->toBe(0);
});

test('the webhook needs no client-version headers and no bearer session', function () {
    $this->postJson('/api/v1/webhooks/google-play', pubsubPush(subscriptionNotification($this->token), 'msg-1012'), ['Authorization' => 'Bearer '.$this->signer->token()])->assertNoContent();
});

test('a refusal writes no database row at all', function () {
    postPush(pubsubPush(subscriptionNotification($this->token)), 'garbage.token.here');

    expect(PlayNotification::query()->count())->toBe(0);
});

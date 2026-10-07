<?php

use App\Http\Api\Logging\RedactSecrets;
use App\Modules\Billing\Application\GooglePlay\Jobs\AcknowledgePlayPurchase;
use App\Modules\Billing\Application\GooglePlay\Jobs\ProcessPlayNotification;
use App\Modules\Billing\Application\GooglePlay\ReceivePlayNotification;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\GooglePlay\VoidedPurchase;
use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use App\Modules\Billing\Infrastructure\GooglePlay\GooglePlayGateway;
use App\Modules\Billing\Infrastructure\GooglePlay\ServiceAccountKey;
use App\Modules\Billing\Infrastructure\GooglePlay\ServiceAccountTokenSource;
use App\Modules\Billing\Infrastructure\Models\PlayNotification;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\PlayPushSigner;

/*
 * Purchase tokens, account references, order ids and Google credentials never
 * leave in a log line, a job payload, an audit entry, a response or a dump
 * (spec 024, FR-055, P-15, security baseline, What must never be logged).
 */

beforeEach(function () {
    $this->freezeTime();
    mapPlayProducts();
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
    [$this->user, $this->credential] = playBuyer();
    $this->reference = app(PlayAccountReferences::class)->for($this->user->id);
    $this->token = playToken('leak').'.SECRET-TOKEN-MARKER';
});

function assertNoLeak(array $secrets): void
{
    $haystacks = [...test()->logged, ...DB::table('jobs')->pluck('payload')->all(), ...AuditEntry::query()->get()->map(fn ($e) => json_encode($e->getAttributes()))->all()];

    foreach ($secrets as $secret) {
        foreach ($haystacks as $haystack) {
            expect((string) $haystack)->not->toContain($secret);
        }
    }
}

test('verify, restore, the webhook, processing and both passes leak nothing, whether they succeed or fail', function () {
    $signer = PlayPushSigner::make();
    $signer->serveCertificates();
    scriptPlay($this->token, ['obfuscatedRef' => $this->reference]);

    $verify = playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $this->token, 'product_id' => 'plus_monthly'])->assertOk();
    $restore = playCall($this->credential, 'post', '/api/v1/billing/google/restore', ['purchases' => [['purchase_token' => $this->token, 'product_id' => 'plus_monthly'], ['purchase_token' => 'unknown.SECRET-TOKEN-MARKER', 'product_id' => 'plus_monthly']]])->assertOk();
    playGateway()->failNext();
    playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => 'down.SECRET-TOKEN-MARKER', 'product_id' => 'plus_monthly'])->assertStatus(503);
    postPush(pubsubPush(subscriptionNotification($this->token, 2), 'leak-1'), $signer->token())->assertNoContent();
    expect(PlayNotification::query()->where('state', 'processed')->count())->toBe(1); // the sync queue ran the job
    postPush(pubsubPush(subscriptionNotification($this->token, 2), 'leak-2'), $signer->forgedToken())->assertStatus(401);
    postPush(pubsubPush(subscriptionNotification($this->token, 2), 'leak-3'), $signer->token(['aud' => 'x']))->assertStatus(401);
    playGateway()->scriptVoided([new VoidedPurchase($this->token, 'refunded')]);
    scriptPlay($this->token, ['state' => PlayState::OnHold, 'obfuscatedRef' => $this->reference]);
    $this->travel(31)->days();
    $this->artisan('billing:play-reconcile')->assertSuccessful();
    $this->artisan('billing:play-sweep')->assertSuccessful();

    foreach ([$verify, $restore] as $response) {
        expect($response->getContent())->not->toContain('SECRET-TOKEN-MARKER')->not->toContain($this->reference);
    }
    assertNoLeak(['SECRET-TOKEN-MARKER', $this->reference, $signer->token()]);
});

test('queued jobs carry identifiers only', function () {
    Queue::fake();
    scriptPlay($this->token);
    playGateway()->failNextAcknowledgements();
    playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $this->token, 'product_id' => 'plus_monthly'])->assertOk();
    app(ReceivePlayNotification::class)->receive(pubsubPush(subscriptionNotification($this->token, 2), 'leak-job'));

    Queue::assertPushed(AcknowledgePlayPurchase::class);
    Queue::assertPushed(ProcessPlayNotification::class);
    foreach (Queue::pushedJobs() as $jobs) {
        foreach ($jobs as $entry) {
            expect(serialize($entry['job']))->not->toContain('SECRET-TOKEN-MARKER')->not->toContain($this->reference);
        }
    }
});

test('the token is sealed at rest: no table holds it readable, only its keyed lookup', function () {
    scriptPlay($this->token);
    playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $this->token, 'product_id' => 'plus_monthly'])->assertOk();
    app(ReceivePlayNotification::class)->receive(pubsubPush(subscriptionNotification($this->token, 2), 'leak-rest'));

    foreach (['play_purchases', 'play_notifications', 'subscriptions', 'idempotency_records', 'audit_entries'] as $table) {
        expect(json_encode(DB::table($table)->get()))->not->toContain('SECRET-TOKEN-MARKER');
    }
});

test('a failing Google call logs a status and a reason, never the URL that carries the token, nor the access token', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $source = new ServiceAccountTokenSource(ServiceAccountKey::from(base64_encode(json_encode(['client_email' => 'svc@example.test', 'private_key_id' => 'k', 'private_key' => $pem])), 'https://oauth2.googleapis.com/token'));
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.SECRET-ACCESS', 'expires_in' => 3600]),
        'androidpublisher.googleapis.com/*' => Http::sequence()->push(['error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']]]], 403)->pushFailedConnection()->push('', 500),
    ]);
    $gateway = new GooglePlayGateway($source, 'com.example.app');

    $gateway->readSubscription($this->token);
    $gateway->readSubscription($this->token);
    $gateway->acknowledge(PlayProductKind::Subscription, 'plus_monthly', $this->token);

    assertNoLeak(['SECRET-TOKEN-MARKER', 'ya29.SECRET-ACCESS', 'PRIVATE KEY']);
});

test('the scrubber redacts references, order ids, assertions and keys at any depth', function () {
    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'm', [
        'purchase_token' => 'a', 'obfuscated_account_id' => 'b', 'account_reference' => 'c', 'order_id' => 'd', 'orderId' => 'e',
        'assertion' => 'f', 'private_key' => 'g', 'authorization' => 'h', 'nested' => ['obfuscatedExternalAccountId' => 'i', 'purchase_id' => 'keep-me'],
    ]);

    $context = (new RedactSecrets)($record)->context;

    expect(array_filter($context, fn ($v) => $v === '[REDACTED]'))->toHaveCount(8)
        ->and($context['nested'])->toBe(['obfuscatedExternalAccountId' => '[REDACTED]', 'purchase_id' => 'keep-me']);
});

test('the account reference is returned only by its own endpoint, to its own account', function () {
    scriptPlay($this->token, ['obfuscatedRef' => $this->reference]);

    $verify = playCall($this->credential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $this->token, 'product_id' => 'plus_monthly'])->assertOk();
    $own = playCall($this->credential, 'get', '/api/v1/billing/google/account-reference')->assertOk();

    expect($own->json('data.obfuscated_account_id'))->toBe($this->reference)->and($verify->getContent())->not->toContain($this->reference);
});

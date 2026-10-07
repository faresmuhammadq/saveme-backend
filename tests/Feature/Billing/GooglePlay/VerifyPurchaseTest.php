<?php

use App\Modules\Billing\Domain\Events\SubscriptionActivated;
use App\Modules\Billing\Domain\GooglePlay\PlayBillingGateway;
use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/*
 * US1: verify one Google Play purchase, link it to the caller, grant it
 * (spec 024, FR-011 to FR-016, FR-004, FR-050).
 */

beforeEach(function () {
    mapPlayProducts();
    [$this->user, $this->credential] = playBuyer();
    $this->token = playToken('verify');
    $this->body = ['purchase_token' => $this->token, 'product_id' => 'plus_monthly'];
});

function verifyPlay(?array $body = null, ?string $key = null, ?string $credential = null)
{
    return playCall($credential ?? test()->credential, 'post', '/api/v1/billing/google/purchases', $body ?? test()->body, $key);
}

function entitlementAudits(): int
{
    return AuditEntry::query()->where('event', 'entitlement_changed')->count();
}

function ownReference(): string
{
    return app(PlayAccountReferences::class)->for(test()->user->id);
}

test('a paid purchase of the caller is 200 with the purchase, the entitlements and the subscription, and one acknowledgement', function () {
    Event::fake([SubscriptionActivated::class]);
    scriptPlay($this->token, ['obfuscatedRef' => ownReference()]);

    $response = verifyPlay();

    $response->assertOk();
    expect($response->json('data.purchase'))->toMatchArray(['product_id' => 'plus_monthly', 'state' => 'active', 'acknowledged' => true, 'auto_renew' => true, 'test' => false])
        ->and($response->json('data.entitlements.plan.name'))->toBe('Plus · Monthly')
        ->and($response->json('data.entitlements.features')['sharing.enabled'])->toBeTrue()
        ->and($response->json('data.subscription'))->toMatchArray(['provider' => 'google_play', 'status' => 'active', 'auto_renew' => true])
        ->and($response->json('meta'))->toBe([])
        ->and(Subscription::query()->count())->toBe(1)
        ->and(entitlementAudits())->toBe(1)
        ->and(playGateway()->acknowledged)->toHaveCount(1)
        ->and($response->getContent())->not->toContain($this->token);
    Event::assertDispatchedTimes(SubscriptionActivated::class, 1);

    $purchase = PlayPurchase::query()->sole();
    expect($purchase->user_id)->toBe($this->user->id)->and($purchase->acknowledged_at)->not->toBeNull()
        ->and($purchase->token_sealed)->not->toContain($this->token)
        ->and($purchase->subscription_id)->toBe(Subscription::query()->value('id'));
});

test('the same key and body replays the answer without calling Google again', function () {
    scriptPlay($this->token);

    $first = verifyPlay(key: 'idem-play-verify-0001')->assertOk();
    $reads = playGateway()->reads;
    $second = verifyPlay(key: 'idem-play-verify-0001');

    expect($second->headers->get('Idempotency-Replayed'))->toBe('true')
        ->and($second->json())->toEqual($first->json())
        ->and(playGateway()->reads)->toBe($reads)
        ->and(playGateway()->acknowledged)->toHaveCount(1);
});

test('the same token under a new key is 200 with nothing changed and nothing acknowledged twice', function () {
    scriptPlay($this->token);

    verifyPlay()->assertOk();
    $again = verifyPlay()->assertOk();

    expect($again->json('data.purchase.state'))->toBe('active')
        ->and($again->json('data.purchase.acknowledged'))->toBeTrue()
        ->and(Subscription::query()->count())->toBe(1)
        ->and(entitlementAudits())->toBe(1)
        ->and(PlayPurchase::query()->count())->toBe(1)
        ->and(playGateway()->acknowledged)->toHaveCount(1);
});

test('a pending purchase is 202, grants nothing, acknowledges nothing, and is linked to the caller', function () {
    scriptPlay($this->token, ['state' => PlayState::Pending, 'expiresAt' => null, 'autoRenew' => false]);

    $response = verifyPlay()->assertStatus(202);

    expect($response->json('data.purchase'))->toMatchArray(['state' => 'pending', 'acknowledged' => false, 'current_period_end' => null])
        ->and($response->json('data.entitlements.plan.name'))->toBe('Free')
        ->and($response->json('data.subscription.provider'))->toBeNull()
        ->and(Subscription::query()->count())->toBe(0)
        ->and(playGateway()->acknowledged)->toBe([])
        ->and(PlayPurchase::query()->sole()->user_id)->toBe($this->user->id);
});

test('a token Google does not know is 422 PURCHASE_NOT_VERIFIED and nothing is stored', function () {
    verifyPlay()->assertStatus(422)->assertJsonPath('code', 'PURCHASE_NOT_VERIFIED');

    expect(PlayPurchase::query()->count())->toBe(0)->and(Subscription::query()->count())->toBe(0);
});

test('a purchase of another product than the one posted is 422 PURCHASE_NOT_VERIFIED', function () {
    scriptPlay($this->token, ['productId' => 'plus_yearly']);

    verifyPlay()->assertStatus(422)->assertJsonPath('code', 'PURCHASE_NOT_VERIFIED');

    expect(PlayPurchase::query()->count())->toBe(0);
});

test('a purchase canceled before it was ever paid is 422 PURCHASE_NOT_VERIFIED', function () {
    scriptPlay($this->token, ['state' => PlayState::PendingCanceled]);

    verifyPlay()->assertStatus(422)->assertJsonPath('code', 'PURCHASE_NOT_VERIFIED');

    expect(PlayPurchase::query()->count())->toBe(0)->and(playGateway()->acknowledged)->toBe([]);
});

test('a Google that cannot be reached is 503 with Retry-After and grants nothing', function () {
    scriptPlay($this->token);
    playGateway()->failNext();

    $response = verifyPlay()->assertStatus(503);

    expect($response->json('code'))->toBe('SERVICE_UNAVAILABLE')->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and(Subscription::query()->count())->toBe(0)->and(playGateway()->acknowledged)->toBe([]);
});

test('a refused verify does not store the idempotency key, so the same key can be retried', function () {
    scriptPlay($this->token);
    playGateway()->failNext();

    verifyPlay(key: 'idem-play-retry-0001')->assertStatus(503);
    verifyPlay(key: 'idem-play-retry-0001')->assertOk();
});

test('a disabled gateway is 503 and grants nothing', function () {
    config(['billing.google_play.driver' => 'disabled']);
    app()->forgetInstance(PlayBillingGateway::class);

    verifyPlay()->assertStatus(503);

    expect(Subscription::query()->count())->toBe(0);
});

test('an old token Google reports expired links without creating a subscription', function (PlayState $state) {
    scriptPlay($this->token, ['state' => $state, 'expiresAt' => now()->subDays(10)->toDateTimeImmutable(), 'autoRenew' => false]);

    $response = verifyPlay()->assertOk();

    expect($response->json('data.purchase.state'))->toBe($state->value)
        ->and($response->json('data.entitlements.plan.name'))->toBe('Free')
        ->and(Subscription::query()->count())->toBe(0)
        ->and(playGateway()->acknowledged)->toBe([])
        ->and(PlayPurchase::query()->sole()->user_id)->toBe($this->user->id);
})->with([PlayState::Expired, PlayState::OnHold, PlayState::Paused]);

test('a product id no plan maps is 422 VALIDATION_FAILED at /product_id', function () {
    $response = verifyPlay(['purchase_token' => $this->token, 'product_id' => 'gold_forever'])->assertStatus(422);

    expect($response->json('code'))->toBe('VALIDATION_FAILED')
        ->and($response->json('errors.0'))->toMatchArray(['pointer' => '/product_id', 'code' => 'INVALID_ENUM_VALUE']);
});

test('a person who has not verified their address can verify a purchase', function () {
    [$unverified, $credential] = (function () {
        $user = person('grace@example.test', verified: false);

        return [$user, signInAs('grace@example.test')->json('data.credential')];
    })();
    scriptPlay($this->token);

    verifyPlay(credential: $credential)->assertOk();

    expect(PlayPurchase::query()->sole()->user_id)->toBe($unverified->id);
});

test('a license tester\'s purchase is granted and flagged as a test', function () {
    scriptPlay($this->token, ['isTest' => true]);

    $response = verifyPlay()->assertOk();

    expect($response->json('data.purchase.test'))->toBeTrue()
        ->and(Subscription::query()->sole()->source_meta['test'])->toBeTrue()
        ->and(PlayPurchase::query()->sole()->is_test)->toBeTrue();
});

test('a lifetime plan maps to a one-time product and grants with no end', function () {
    $plan = Plan::query()->where('code', 'plus_yearly')->sole();
    $plan->period_type = 'lifetime';
    $plan->save();
    playGateway()->scriptProduct($this->token, playRecord(['productId' => 'plus_yearly', 'kind' => PlayProductKind::OneTime, 'expiresAt' => null, 'autoRenew' => false]));

    $response = verifyPlay(['purchase_token' => $this->token, 'product_id' => 'plus_yearly'])->assertOk();

    expect($response->json('data.purchase.current_period_end'))->toBeNull()
        ->and($response->json('data.subscription.current_period_end'))->toBeNull()
        ->and(Subscription::query()->sole()->current_period_end)->toBeNull()
        ->and(playGateway()->acknowledged[0]['kind'])->toBe('one_time');
});

test('a body member that is not a field is rejected, and no price is ever accepted', function () {
    scriptPlay($this->token);

    $response = verifyPlay([...$this->body, 'amount_minor' => 1])->assertStatus(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/amount_minor', 'code' => 'UNKNOWN_FIELD'])
        ->and(PlayPurchase::query()->count())->toBe(0);
});

test('every refusal of a request body is a field error', function (array $body, string $pointer, string $code) {
    $response = verifyPlay($body)->assertStatus(422);

    expect(collect($response->json('errors'))->map(fn (array $e) => [$e['pointer'], $e['code']])->all())->toContain([$pointer, $code]);
})->with([
    'no token' => [['product_id' => 'plus_monthly'], '/purchase_token', 'REQUIRED'],
    'an integer token' => [['purchase_token' => 5, 'product_id' => 'plus_monthly'], '/purchase_token', 'INVALID_TYPE'],
    'an empty token' => [['purchase_token' => '', 'product_id' => 'plus_monthly'], '/purchase_token', 'REQUIRED'],
    'a huge token' => [['purchase_token' => str_repeat('x', 2049), 'product_id' => 'plus_monthly'], '/purchase_token', 'OUT_OF_RANGE'],
    'no product' => [['purchase_token' => 'x'], '/product_id', 'REQUIRED'],
]);

test('verify is limited to 20 per hour per person, spent after validation, never by a replay', function () {
    RateLimiter::clear('billing-play-verify:'.$this->user->id);
    scriptPlay($this->token);

    verifyPlay(['purchase_token' => '', 'product_id' => 'plus_monthly'])->assertStatus(422); // a malformed request costs nothing
    for ($i = 0; $i < 20; $i++) {
        verifyPlay(key: 'verify-budget-key-'.$i.'-0000');
    }
    $replay = verifyPlay(key: 'verify-budget-key-0-0000');
    $next = verifyPlay(key: 'verify-budget-key-new-0000');

    expect($replay->headers->get('Idempotency-Replayed'))->toBe('true');
    $next->assertStatus(429);
    expect($next->json('code'))->toBe('RATE_LIMITED')->and($next->headers->get('Retry-After'))->not->toBeNull();
});

test('verify needs an Idempotency-Key', function () {
    $this->withHeaders([...asCredential($this->credential), 'X-Client-Platform' => 'android'])->postJson('/api/v1/billing/google/purchases', $this->body)
        ->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
});

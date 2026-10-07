<?php

use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Infrastructure\Models\PlayPurchase;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Support\Facades\RateLimiter;

/*
 * US5: restore my purchases (spec 024, FR-017 to FR-019). One result per item
 * in request order, never the token, 503 only when nothing reached Google.
 */

beforeEach(function () {
    $this->freezeTime();
    mapPlayProducts();
    [$this->ada, $this->adaCredential] = playBuyer('ada@example.test');
    [$this->bob, $this->bobCredential] = playBuyer('bob@example.test');
});

function restoreAs(string $credential, array $items, ?string $key = null)
{
    return playCall($credential, 'post', '/api/v1/billing/google/restore', ['purchases' => $items], $key);
}

function playRestoreItem(string $token, string $product = 'plus_monthly'): array
{
    return ['purchase_token' => $token, 'product_id' => $product];
}

test('each owned purchase gets one result in request order, and the entitlements come back', function () {
    $mine = playToken('mine');
    $already = playToken('already');
    $theirs = playToken('theirs');
    $bad = playToken('bad');
    $slow = playToken('slow');
    scriptPlay($mine);
    scriptPlay($already);
    scriptPlay($theirs, ['productId' => 'plus_yearly']);
    scriptPlay($slow, ['state' => PlayState::Pending, 'expiresAt' => null]);
    playCall($this->adaCredential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $already, 'product_id' => 'plus_monthly'])->assertOk();
    playCall($this->bobCredential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $theirs, 'product_id' => 'plus_yearly'])->assertOk();

    $response = restoreAs($this->adaCredential, [playRestoreItem($mine), playRestoreItem($already), playRestoreItem($theirs, 'plus_yearly'), playRestoreItem($bad), playRestoreItem($slow)])->assertOk();

    expect($response->json('data.results'))->toBe([
        ['index' => 0, 'product_id' => 'plus_monthly', 'outcome' => 'linked'],
        ['index' => 1, 'product_id' => 'plus_monthly', 'outcome' => 'already_yours'],
        ['index' => 2, 'product_id' => 'plus_yearly', 'outcome' => 'linked_elsewhere'],
        ['index' => 3, 'product_id' => 'plus_monthly', 'outcome' => 'not_verified'],
        ['index' => 4, 'product_id' => 'plus_monthly', 'outcome' => 'pending'],
    ])->and($response->json('data.entitlements.plan.name'))->toBe('Plus · Monthly')
        ->and($response->json('data.subscription.provider'))->toBe('google_play')
        ->and($response->json('meta'))->toBe([])
        ->and($response->getContent())->not->toContain($mine)->not->toContain($theirs)->not->toContain($slow);
});

test('a purchase linked elsewhere reveals nothing about the other account', function () {
    $theirs = playToken('theirs');
    scriptPlay($theirs);
    playCall($this->bobCredential, 'post', '/api/v1/billing/google/purchases', ['purchase_token' => $theirs, 'product_id' => 'plus_monthly'])->assertOk();

    $response = restoreAs($this->adaCredential, [playRestoreItem($theirs)])->assertOk();

    expect($response->json('data.results.0.outcome'))->toBe('linked_elsewhere')
        ->and($response->getContent())->not->toContain('bob')->not->toContain($this->bob->id)
        ->and(Subscription::query()->where('user_id', $this->ada->id)->count())->toBe(0);
});

test('newly linked purchases are acknowledged by the server', function () {
    $mine = playToken('mine');
    scriptPlay($mine);

    restoreAs($this->adaCredential, [playRestoreItem($mine)])->assertOk();

    expect(playGateway()->wasAcknowledged($mine))->toBeTrue();
});

test('some items unreachable is still 200 with those items unavailable', function () {
    $first = playToken('first');
    $second = playToken('second');
    scriptPlay($first);
    scriptPlay($second);
    playGateway()->failNext(1);

    $response = restoreAs($this->adaCredential, [playRestoreItem($first), playRestoreItem($second)])->assertOk();

    expect(array_column($response->json('data.results'), 'outcome'))->toBe(['unavailable', 'linked']);
});

test('nothing reaching Google is 503', function () {
    $first = playToken('first');
    scriptPlay($first);
    playGateway()->failNext(5);

    $response = restoreAs($this->adaCredential, [playRestoreItem($first)])->assertStatus(503);

    expect($response->json('code'))->toBe('SERVICE_UNAVAILABLE')->and($response->headers->get('Retry-After'))->not->toBeNull();
});

test('an empty list refreshes the caller\'s linked purchases from Google', function () {
    $mine = playToken('mine');
    scriptPlay($mine);
    restoreAs($this->adaCredential, [playRestoreItem($mine)])->assertOk();
    scriptPlay($mine, ['expiresAt' => now()->addDays(90)->toDateTimeImmutable(), 'acknowledged' => true]);

    $response = restoreAs($this->adaCredential, [])->assertOk();

    expect($response->json('data.results'))->toBe([])
        ->and(Subscription::query()->sole()->current_period_end->equalTo(now()->addDays(90)))->toBeTrue();
});

test('the refresh also covers linked purchases the phone did not send', function () {
    $mine = playToken('mine');
    scriptPlay($mine);
    restoreAs($this->adaCredential, [playRestoreItem($mine)])->assertOk();
    scriptPlay($mine, ['state' => PlayState::OnHold, 'acknowledged' => true]);

    restoreAs($this->adaCredential, [playRestoreItem(playToken('unknown'))])->assertOk();

    expect(Subscription::query()->sole()->status)->toBe('expired');
});

test('an empty list with nothing linked is 200, not 503', function () {
    restoreAs($this->adaCredential, [])->assertOk();
});

test('an empty list with every refresh unreachable is 503', function () {
    $mine = playToken('mine');
    scriptPlay($mine);
    restoreAs($this->adaCredential, [playRestoreItem($mine)])->assertOk();
    playGateway()->failNext(5);

    restoreAs($this->adaCredential, [])->assertStatus(503);
});

test('more than 25 items is 422 OUT_OF_RANGE at /purchases', function () {
    $items = array_map(fn (int $i) => playRestoreItem(playToken((string) $i)), range(1, 26));

    $response = restoreAs($this->adaCredential, $items)->assertStatus(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/purchases', 'code' => 'OUT_OF_RANGE'])->and(PlayPurchase::query()->count())->toBe(0);
});

test('a token repeated in one request is INVALID_TYPE at the repeat', function () {
    $response = restoreAs($this->adaCredential, [playRestoreItem('same-token'), playRestoreItem('other'), playRestoreItem('same-token')])->assertStatus(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/purchases/2/purchase_token', 'code' => 'INVALID_TYPE']);
});

test('field faults are reported per item', function () {
    $response = restoreAs($this->adaCredential, [['purchase_token' => 'x'], ['product_id' => 'plus_monthly'], playRestoreItem('y', 'gold_forever')])->assertStatus(422);

    expect(collect($response->json('errors'))->map(fn ($e) => [$e['pointer'], $e['code']])->sort()->values()->all())->toBe([
        ['/purchases/0/product_id', 'REQUIRED'], ['/purchases/1/purchase_token', 'REQUIRED'], ['/purchases/2/product_id', 'INVALID_ENUM_VALUE'],
    ]);
});

test('purchases is required and nothing else is a member', function () {
    playCall($this->adaCredential, 'post', '/api/v1/billing/google/restore', [])->assertStatus(422)->assertJsonPath('errors.0.pointer', '/purchases');
    playCall($this->adaCredential, 'post', '/api/v1/billing/google/restore', ['purchases' => [], 'price' => 1])->assertStatus(422)->assertJsonPath('errors.0.code', 'UNKNOWN_FIELD');
});

test('restore is limited to 10 per hour per person, spent after validation, never by a replay', function () {
    RateLimiter::clear('billing-play-restore:'.$this->ada->id);

    restoreAs($this->adaCredential, [[]])->assertStatus(422); // a malformed request costs nothing
    for ($i = 0; $i < 10; $i++) {
        restoreAs($this->adaCredential, [], 'restore-budget-key-'.$i.'-0000')->assertOk();
    }
    $replay = restoreAs($this->adaCredential, [], 'restore-budget-key-0-0000');
    $eleventh = restoreAs($this->adaCredential, [], 'restore-budget-key-new-0000');

    expect($replay->status())->toBe(200)->and($replay->headers->get('Idempotency-Replayed'))->toBe('true');
    $eleventh->assertStatus(429);
    expect($eleventh->json('code'))->toBe('RATE_LIMITED')->and($eleventh->headers->get('Retry-After'))->not->toBeNull();
});

test('restore needs an Idempotency-Key and a signed-in caller', function () {
    $this->withHeaders([...asCredential($this->adaCredential), 'X-Client-Platform' => 'android'])->postJson('/api/v1/billing/google/restore', ['purchases' => []])->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
    $this->flushHeaders();
    $this->withHeaders([...jsonHeaders(), 'Idempotency-Key' => 'restore-anon-key-000000'])->postJson('/api/v1/billing/google/restore', ['purchases' => []])->assertStatus(401);
});

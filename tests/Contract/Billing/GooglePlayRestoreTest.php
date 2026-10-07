<?php

use App\Modules\Billing\Domain\GooglePlay\PlayState;
use Illuminate\Support\Facades\RateLimiter;

/*
 * POST /billing/google/restore against docs/openapi/paths/google-play.yaml,
 * every documented status (spec 024, US5).
 */

beforeEach(function () {
    mapPlayProducts();
    [$this->user, $this->credential] = playBuyer();
});

function restoreCall(array $purchases, ?string $key = null, ?string $credential = null)
{
    return playCall($credential ?? test()->credential, 'post', '/api/v1/billing/google/restore', ['purchases' => $purchases], $key);
}

test('200: one result per item and the entitlements', function () {
    $mine = playToken('mine');
    $slow = playToken('slow');
    scriptPlay($mine);
    scriptPlay($slow, ['state' => PlayState::Pending, 'expiresAt' => null]);

    $response = restoreCall([['purchase_token' => $mine, 'product_id' => 'plus_monthly'], ['purchase_token' => $slow, 'product_id' => 'plus_monthly'], ['purchase_token' => playToken('bad'), 'product_id' => 'plus_yearly']])
        ->assertValidRequest()->assertValidResponse(200);

    expect(array_column($response->json('data.results'), 'outcome'))->toBe(['linked', 'pending', 'not_verified']);
});

test('200: an empty list refreshes the caller\'s purchases', function () {
    restoreCall([])->assertValidRequest()->assertValidResponse(200)->assertJsonPath('data.results', []);
});

test('200: another account\'s purchase is linked_elsewhere', function () {
    $theirs = playToken('theirs');
    scriptPlay($theirs);
    [, $other] = playBuyer('bob@example.test');
    restoreCall([['purchase_token' => $theirs, 'product_id' => 'plus_monthly']], null, $other)->assertOk();

    restoreCall([['purchase_token' => $theirs, 'product_id' => 'plus_monthly']])->assertValidResponse(200)->assertJsonPath('data.results.0.outcome', 'linked_elsewhere');
});

test('400: the key is required and well formed', function () {
    $this->withHeaders([...asCredential($this->credential), 'X-Client-Platform' => 'android'])->postJson('/api/v1/billing/google/restore', ['purchases' => []])->assertValidResponse(400);

    restoreCall([], 'short')->assertValidResponse(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
});

test('401: it needs a credential', function () {
    $this->flushHeaders();

    $this->withHeaders([...jsonHeaders(), 'Idempotency-Key' => 'contract-anon-key-0002'])->postJson('/api/v1/billing/google/restore', ['purchases' => []])->assertValidResponse(401);
});

test('409: IDEMPOTENCY_KEY_REUSED with another body', function () {
    restoreCall([], 'contract-restore-reuse-001')->assertOk();

    restoreCall([['purchase_token' => 'x', 'product_id' => 'plus_monthly']], 'contract-restore-reuse-001')->assertValidResponse(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
});

test('422: more than 25 items, a repeated token, a field fault', function () {
    $many = array_map(fn (int $i) => ['purchase_token' => playToken((string) $i), 'product_id' => 'plus_monthly'], range(1, 26));

    restoreCall($many)->assertValidResponse(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    restoreCall([['purchase_token' => 'same', 'product_id' => 'plus_monthly'], ['purchase_token' => 'same', 'product_id' => 'plus_monthly']])->assertValidResponse(422);
    restoreCall([['purchase_token' => 'x']])->assertValidResponse(422);
});

test('406, 415, 400 and 426: the shared refusals', function () {
    assertSharedRefusals('POST', '/billing/google/restore', ['purchases' => []], $this->credential);
});

test('429: the eleventh restore in an hour', function () {
    RateLimiter::clear('billing-play-restore:'.$this->user->id);
    foreach (range(1, 10) as $i) {
        restoreCall([], 'contract-restore-budget-'.$i.'-01');
    }

    $response = restoreCall([], 'contract-restore-budget-over-01')->assertValidResponse(429);

    expect($response->json('code'))->toBe('RATE_LIMITED');
});

test('503: nothing reached Google', function () {
    $token = playToken('down');
    scriptPlay($token);
    playGateway()->failNext(3);

    restoreCall([['purchase_token' => $token, 'product_id' => 'plus_monthly']])->assertValidResponse(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
});

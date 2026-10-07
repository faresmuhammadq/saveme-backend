<?php

use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\Idempotency\CanonicalJson;
use App\Modules\Billing\Domain\Ports\IdempotencyStore;
use Illuminate\Support\Facades\RateLimiter;

/*
 * POST /billing/google/purchases against docs/openapi/paths/google-play.yaml,
 * every documented status (spec 024, US1, US2).
 */

beforeEach(function () {
    mapPlayProducts();
    [$this->user, $this->credential] = playBuyer();
    $this->token = playToken('contract');
    $this->body = ['purchase_token' => $this->token, 'product_id' => 'plus_monthly'];
});

function purchasesCall(array $body, ?string $key = null, ?string $credential = null)
{
    return playCall($credential ?? test()->credential, 'post', '/api/v1/billing/google/purchases', $body, $key);
}

test('200: a new subscription, acknowledged', function () {
    scriptPlay($this->token);

    $response = purchasesCall($this->body)->assertValidRequest()->assertValidResponse(200);

    expect(array_keys($response->json('data')))->toBe(['purchase', 'entitlements', 'subscription'])
        ->and($response->json('data.purchase.acknowledged'))->toBeTrue()
        ->and($response->json('data.purchase.current_period_end'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
        ->and($response->json('meta'))->toBe([]);
});

test('200: granted while Google\'s acknowledgement is still being retried', function () {
    scriptPlay($this->token);
    playGateway()->failNextAcknowledgements();

    $response = purchasesCall($this->body)->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data.purchase.acknowledged'))->toBeFalse();
});

test('200: an old token reported expired, on hold or paused', function (PlayState $state) {
    scriptPlay($this->token, ['state' => $state, 'expiresAt' => now()->subDays(5)->toDateTimeImmutable(), 'autoRenew' => false]);

    purchasesCall($this->body)->assertValidRequest()->assertValidResponse(200);
})->with([PlayState::Expired, PlayState::OnHold, PlayState::Paused]);

test('202: a pending purchase', function () {
    scriptPlay($this->token, ['state' => PlayState::Pending, 'expiresAt' => null, 'autoRenew' => false]);

    $response = purchasesCall($this->body)->assertValidRequest()->assertValidResponse(202);

    expect($response->json('data.purchase.state'))->toBe('pending');
});

test('200 replayed: the same key and body, with Idempotency-Replayed', function () {
    scriptPlay($this->token);
    purchasesCall($this->body, 'contract-replay-key-0001')->assertValidResponse(200);

    purchasesCall($this->body, 'contract-replay-key-0001')->assertValidResponse(200)->assertHeader('Idempotency-Replayed', 'true');
});

test('400: the key is required, and must be well formed', function () {
    $this->withHeaders([...asCredential($this->credential), 'X-Client-Platform' => 'android'])->postJson('/api/v1/billing/google/purchases', $this->body)
        ->assertValidResponse(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

    purchasesCall($this->body, 'short')->assertValidResponse(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
});

test('401: it needs a credential', function () {
    $this->flushHeaders();

    $this->withHeaders([...jsonHeaders(), 'Idempotency-Key' => 'contract-anon-key-0001'])->postJson('/api/v1/billing/google/purchases', $this->body)
        ->assertValidResponse(401)->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('409: PURCHASE_ALREADY_LINKED, naming no one', function () {
    scriptPlay($this->token);
    purchasesCall($this->body)->assertOk();
    [, $other] = playBuyer('bob@example.test');

    $response = purchasesCall($this->body, null, $other)->assertValidResponse(409)->assertJsonPath('code', 'PURCHASE_ALREADY_LINKED');

    expect($response->getContent())->not->toContain('ada@example.test');
});

test('409: IDEMPOTENCY_KEY_REUSED with another body', function () {
    scriptPlay($this->token);
    purchasesCall($this->body, 'contract-reuse-key-0001')->assertOk();

    purchasesCall([...$this->body, 'purchase_token' => playToken('other')], 'contract-reuse-key-0001')->assertValidResponse(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
});

test('409: IDEMPOTENCY_REQUEST_IN_PROGRESS while the first is still running', function () {
    $key = 'contract-busy-key-0001';
    $fingerprint = CanonicalJson::fingerprint('POST', '/api/v1/billing/google/purchases', json_encode($this->body));
    app(IdempotencyStore::class)->begin('user:'.$this->user->id, $key, $fingerprint, now()->toDateTimeImmutable());

    $response = purchasesCall($this->body, $key)->assertValidResponse(409)->assertJsonPath('code', 'IDEMPOTENCY_REQUEST_IN_PROGRESS');

    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

test('422: PURCHASE_NOT_VERIFIED for a token Google does not know', function () {
    purchasesCall($this->body)->assertValidRequest()->assertValidResponse(422)->assertJsonPath('code', 'PURCHASE_NOT_VERIFIED');
});

test('422: VALIDATION_FAILED for a missing, mistyped, too long or unknown member and an unmapped product', function () {
    $response = purchasesCall(['purchase_token' => 5, 'product_id' => 'gold_forever', 'price' => 1])->assertValidResponse(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    purchasesCall([])->assertValidResponse(422);
    purchasesCall([...$this->body, 'purchase_token' => str_repeat('x', 2049)])->assertValidResponse(422);

    expect(collect($response->json('errors'))->pluck('code')->all())->toContain('INVALID_TYPE', 'INVALID_ENUM_VALUE', 'UNKNOWN_FIELD');
});

test('406, 415, 400 and 426: the shared refusals', function () {
    assertSharedRefusals('POST', '/billing/google/purchases', $this->body, $this->credential);
});

test('429: the twenty-first verification in an hour', function () {
    RateLimiter::clear('billing-play-verify:'.$this->user->id);
    foreach (range(1, 20) as $i) {
        purchasesCall($this->body, 'contract-budget-key-'.$i.'-0001');
    }

    $response = purchasesCall($this->body, 'contract-budget-key-over-0001')->assertValidResponse(429);

    expect($response->json('code'))->toBe('RATE_LIMITED')->and($response->headers->get('Retry-After'))->not->toBeNull();
});

test('503: Google cannot be reached', function () {
    scriptPlay($this->token);
    playGateway()->failNext();

    $response = purchasesCall($this->body)->assertValidResponse(503);

    expect($response->json('code'))->toBe('SERVICE_UNAVAILABLE')->and($response->headers->get('Retry-After'))->not->toBeNull();
});

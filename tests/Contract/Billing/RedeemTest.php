<?php

use App\Modules\Billing\Domain\RedeemCodes\RedeemCode;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode as RedeemCodeRow;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * POST /billing/redeem (spec 020, US3): the in-app "Enter code".
 */

beforeEach(function () {
    $this->user = person('ada@example.test');
    $this->credential = signInAs('ada@example.test')->json('data.credential');
});

/**
 * @return array{typed: string, claim: PaymentClaim}
 */
function mailedCode(string $email = 'ada@example.test', string $plan = 'plus_yearly', array $code = []): array
{
    $typed = RedeemCode::generate()->display();
    $claim = PaymentClaim::factory()->forAddress($email)->forPlan($plan)->approved()->create();
    RedeemCodeRow::factory()->forCode($typed)->state(['claim_id' => $claim->id, ...$code])->create();

    return ['typed' => $typed, 'claim' => $claim];
}

function redeemCall(string $credential, array $body, ?string $key = null, string $platform = 'android'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders([...asCredential($credential), 'X-Client-Platform' => $platform, 'Idempotency-Key' => $key ?? 'redeem-'.Str::lower(Str::random(24))])
        ->postJson('/api/v1/billing/redeem', $body);
}

test('a code is 200 with the redeemed subscription, the governing one and the entitlements', function () {
    ['typed' => $typed] = mailedCode();

    $response = redeemCall($this->credential, ['code' => $typed])->assertValidRequest()->assertValidResponse(200);

    expect(array_keys($response->json('data')))->toBe(['subscription', 'governing', 'entitlements'])
        ->and($response->json('data.subscription'))->toMatchArray(['provider' => 'instapay', 'status' => 'active', 'plan' => ['id' => planIdByCode('plus_yearly'), 'name' => 'Plus · Yearly']])
        ->and($response->json('data.subscription.id'))->toBe(Subscription::query()->sole()->id)
        ->and($response->json('data.subscription.current_period_end'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
        ->and($response->json('meta'))->toBe([])
        ->and($response->getContent())->not->toContain($typed)->not->toContain('ada@example.test');
});

test('governing and entitlements equal what /me/subscription and /me/entitlements answer right after', function () {
    ['typed' => $typed] = mailedCode();

    $redeemed = redeemCall($this->credential, ['code' => $typed])->assertValidResponse(200);
    $subscription = $this->withHeaders(asCredential($this->credential))->get('/api/v1/me/subscription')->assertValidResponse(200);
    $entitlements = $this->withHeaders(asCredential($this->credential))->get('/api/v1/me/entitlements')->assertValidResponse(200);

    expect($redeemed->json('data.governing'))->toEqual($subscription->json('data'))
        ->and($redeemed->json('data.entitlements'))->toEqual($entitlements->json('data'));
});

test('a loosely typed code works, from the app and from the web', function (string $platform) {
    mailedCode(code: ['code_hash' => RedeemCode::tryParse('7KQ2-MX4D-9TRB-H3WE')->hash()]);

    redeemCall($this->credential, ['code' => ' 7kq2 mx4d 9trb h3we '], platform: $platform)->assertValidRequest()->assertValidResponse(200);
})->with(['android', 'web']);

test('a Play subscription ending later keeps governing, and the answer says so', function () {
    Subscription::factory()->provider('google_play')->forPlan('plus_yearly')->endingIn(400)->create(['user_id' => $this->user->id]);
    ['typed' => $typed] = mailedCode(plan: 'plus_monthly');

    $response = redeemCall($this->credential, ['code' => $typed])->assertValidResponse(200);

    expect($response->json('data.subscription.provider'))->toBe('instapay')
        ->and($response->json('data.governing.provider'))->toBe('google_play')
        ->and($response->json('data.governing.plan.name'))->toBe('Plus · Yearly');
});

test('it needs a credential', function () {
    $this->withHeaders([...jsonHeaders(), 'Idempotency-Key' => 'redeem-0123456789abcdef'])->postJson('/api/v1/billing/redeem', ['code' => '7KQ2-MX4D-9TRB-H3WE'])
        ->assertValidResponse(401)->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('the idempotency key is required and well formed', function () {
    app('auth')->forgetGuards();
    $this->withHeaders(asCredential($this->credential))->postJson('/api/v1/billing/redeem', ['code' => '7KQ2-MX4D-9TRB-H3WE'])
        ->assertValidResponse(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

    redeemCall($this->credential, ['code' => '7KQ2-MX4D-9TRB-H3WE'], 'short')->assertValidResponse(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
});

test('the same key and body replay the stored 200 and spend no budget; another body is 409 IDEMPOTENCY_KEY_REUSED', function () {
    ['typed' => $typed] = mailedCode();
    $key = 'redeem-key-0000000001';

    $first = redeemCall($this->credential, ['code' => $typed], $key)->assertValidResponse(200);
    $replay = redeemCall($this->credential, ['code' => $typed], $key)->assertValidResponse(200)->assertHeader('Idempotency-Replayed', 'true');
    redeemCall($this->credential, ['code' => RedeemCode::generate()->display()], $key)->assertValidResponse(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

    expect($replay->json())->toEqual($first->json())
        ->and($replay->getContent())->toMatch('/"meta": ?\{\}/')
        ->and(Subscription::query()->count())->toBe(1)
        ->and(RateLimiter::attempts('billing-redeem:'.$this->user->id))->toBe(1);
});

test('a code that is not 16 code characters is 422 at /code, and an unknown member is UNKNOWN_FIELD', function (mixed $code) {
    $response = redeemCall($this->credential, ['code' => $code])->assertValidResponse(422);

    expect($response->json('errors.0.pointer'))->toBe('/code')
        ->and($response->json('errors.0.code'))->toBe(in_array($code, [null, ''], true) ? 'REQUIRED' : 'INVALID_TYPE');
})->with(['not a code', 'ABCD-EFGH-JKMN-PQRU', '', null, 12345678901234]);

test('an unknown member is refused', function () {
    $response = redeemCall($this->credential, ['code' => '7KQ2-MX4D-9TRB-H3WE', 'plan' => 'x'])->assertValidResponse(422);

    expect($response->json('errors'))->toContain(['pointer' => '/plan', 'code' => 'UNKNOWN_FIELD', 'detail' => $response->json('errors.0.detail')]);
});

test('an unknown or revoked code is 404 NOT_FOUND', function () {
    ['typed' => $revoked] = mailedCode(code: ['status' => 'revoked', 'revoked_at' => now()]);

    redeemCall($this->credential, ['code' => RedeemCode::generate()->display()])->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
    redeemCall($this->credential, ['code' => $revoked])->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
});

test('a code another account redeemed is 409 REDEEM_CODE_USED', function () {
    ['typed' => $typed] = mailedCode();
    redeemCall($this->credential, ['code' => $typed])->assertValidResponse(200);

    person('bob@example.test');
    $bob = signInAs('bob@example.test')->json('data.credential');

    redeemCall($bob, ['code' => $typed])->assertValidResponse(409)->assertJsonPath('code', 'REDEEM_CODE_USED')->assertJsonPath('status', 409);
});

test('the same account entering its code again with a new key is 200 with the same subscription', function () {
    ['typed' => $typed] = mailedCode();

    $first = redeemCall($this->credential, ['code' => $typed])->assertValidResponse(200);
    $again = redeemCall($this->credential, ['code' => $typed])->assertValidResponse(200)->assertHeaderMissing('Idempotency-Replayed');

    expect($again->json('data.subscription.id'))->toBe($first->json('data.subscription.id'))
        ->and(Subscription::query()->count())->toBe(1);
});

test('a code past its 30 days is 410 REDEEM_CODE_EXPIRED', function () {
    ['typed' => $typed] = mailedCode(code: ['expires_at' => now()->subSecond()]);

    redeemCall($this->credential, ['code' => $typed])->assertValidResponse(410)->assertJsonPath('code', 'REDEEM_CODE_EXPIRED');
    expect(Subscription::query()->count())->toBe(0);
});

test('a code sent to another address is 403 REDEEM_CODE_EMAIL_MISMATCH, naming no address', function () {
    ['typed' => $typed] = mailedCode('someone.else@example.test');

    $response = redeemCall($this->credential, ['code' => $typed])->assertValidResponse(403)->assertJsonPath('code', 'REDEEM_CODE_EMAIL_MISMATCH');

    expect($response->json('detail'))->toBe('Sign in with the email address this code was sent to.')->not->toContain('@')
        ->and($response->getContent())->not->toContain('someone.else');
});

test('the right address, unverified, is 403 EMAIL_NOT_VERIFIED', function () {
    person('new@example.test', verified: false);
    $credential = signInAs('new@example.test')->json('data.credential');
    ['typed' => $typed] = mailedCode('new@example.test');

    redeemCall($credential, ['code' => $typed])->assertValidResponse(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

test('the eleventh attempt in an hour is 429; a refused format and a replay do not count', function () {
    foreach (range(1, 5) as $ignored) {
        redeemCall($this->credential, ['code' => 'not a code'])->assertStatus(422);
    }

    ['typed' => $typed] = mailedCode();
    $key = 'redeem-key-0000000002';
    redeemCall($this->credential, ['code' => $typed], $key)->assertStatus(200);
    foreach (range(1, 5) as $ignored) {
        redeemCall($this->credential, ['code' => $typed], $key)->assertStatus(200)->assertHeader('Idempotency-Replayed', 'true');
    }

    foreach (range(1, 9) as $ignored) {
        redeemCall($this->credential, ['code' => RedeemCode::generate()->display()])->assertStatus(404);
    }

    $response = redeemCall($this->credential, ['code' => RedeemCode::generate()->display()])->assertValidResponse(429);

    expect($response->json('code'))->toBe('RATE_LIMITED')
        ->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get('RateLimit-Limit'))->toBe('10');
});

test('the shared refusals: not acceptable, unsupported media type, malformed JSON, upgrade required', function () {
    assertSharedRefusals('POST', '/billing/redeem', ['code' => '7KQ2-MX4D-9TRB-H3WE'], $this->credential);
});

<?php

use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
 * POST /billing/instapay/claims (spec 020, US1): public, website only,
 * answered alike for every address.
 */

beforeEach(function () {
    Storage::fake('local');
    RateLimiter::clear('billing-claim-origin:127.0.0.1');
});

function claimResponse(array $body, array $headers = []): TestResponse
{
    if (($body['plan_id'] ?? null) === '__free__') {
        $body['plan_id'] = planIdByCode('free');
    }

    if (($body['screenshot']['data'] ?? null) === '__jpeg__') {
        $body['screenshot']['data'] = receiptBase64('jpeg');
    }

    if (($body['screenshot']['data'] ?? null) === '__big__') {
        $body['screenshot']['data'] = receiptBase64('png', 3145729);
    }

    return test()->withHeaders([...claimHeaders(), ...$headers])->postJson('/api/v1/billing/instapay/claims', $body);
}

test('a valid claim is 202 with the claim receipt, in the plan and the language asked for', function () {
    $response = claimResponse(claimBody(['screenshot' => ['media_type' => 'image/png', 'data' => receiptBase64('png')]]), ['Accept-Language' => 'ar'])
        ->assertValidRequest()
        ->assertValidResponse(202)
        ->assertHeader('Content-Language', 'ar');

    expect($response->json('data'))->toMatchArray([
        'status' => 'submitted',
        'plan' => ['id' => planIdByCode('plus_yearly'), 'name' => 'بلس · سنوي'],
        'amount' => ['amount_minor' => 129000, 'currency' => 'EGP'],
    ])->and(array_keys($response->json('data')))->toBe(['id', 'status', 'plan', 'amount', 'expires_at'])
        ->and($response->json('data.id'))->toBeUuidV7()
        ->and($response->json('data.expires_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
        ->and($response->getContent())->not->toContain('mona@example.test')
        ->and(PaymentClaim::query()->sole()->locale)->toBe('ar');
});

test('without a screenshot and in English it is 202 too', function () {
    claimResponse(claimBody(['reference' => 'IPN-20261005-7731']))->assertValidRequest()->assertValidResponse(202)->assertHeader('Content-Language', 'en');
});

test('a registered and an unregistered address get the same status and the same members', function () {
    person('ada@example.test');

    $registered = claimResponse(claimBody(['email' => 'ada@example.test', 'reference' => 'REFERENCE0001']))->assertValidResponse(202);
    $unregistered = claimResponse(claimBody(['email' => 'nobody@example.test', 'reference' => 'REFERENCE0002']))->assertValidResponse(202);

    expect($registered->status())->toBe($unregistered->status())
        ->and(array_keys($registered->json('data')))->toBe(array_keys($unregistered->json('data')))
        ->and(array_keys($registered->json()))->toBe(array_keys($unregistered->json()))
        ->and($registered->json('meta'))->toBe([])
        ->and($registered->getContent())->not->toContain('ada@example.test')
        ->and($unregistered->getContent())->not->toContain('nobody@example.test');
});

test('any platform but web gets 404, even a valid claim', function () {
    claimResponse(claimBody(), ['X-Client-Platform' => 'android'])
        ->assertValidResponse(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect(PaymentClaim::query()->count())->toBe(0);
});

test('each fault is 422 with its pointer and field code', function (array $changes, string $pointer, string $code) {
    $response = claimResponse(claimBody($changes))->assertValidResponse(422)->assertJsonPath('code', 'VALIDATION_FAILED');

    expect($response->json('errors'))->toContain(['pointer' => $pointer, 'code' => $code, 'detail' => $response->json('errors.0.detail')])
        ->and(PaymentClaim::query()->count())->toBe(0);
})->with([
    'an amount off by one' => [['amount_minor' => 128999], '/amount_minor', 'OUT_OF_RANGE'],
    'a decimal amount' => [['amount_minor' => 1290.5], '/amount_minor', 'AMOUNT_NOT_INTEGER'],
    'a string amount' => [['amount_minor' => '129000'], '/amount_minor', 'AMOUNT_NOT_INTEGER'],
    'another currency' => [['currency' => 'USD'], '/currency', 'CURRENCY_MISMATCH'],
    'a lower-case currency' => [['currency' => 'egp'], '/currency', 'CURRENCY_MISMATCH'],
    'the free plan' => [['plan_id' => '__free__'], '/plan_id', 'INVALID_ENUM_VALUE'],
    'an unknown plan' => [['plan_id' => '0192f7a0-0000-7000-8000-00000000ffff'], '/plan_id', 'INVALID_ENUM_VALUE'],
    'a plan id that is no id' => [['plan_id' => 'plus'], '/plan_id', 'INVALID_ENUM_VALUE'],
    'a short reference' => [['reference' => 'AB 12'], '/reference', 'INVALID_TYPE'],
    'a reference with symbols' => [['reference' => 'ABCD1234!!'], '/reference', 'INVALID_TYPE'],
    'a very long reference' => [['reference' => str_repeat('A', 40)], '/reference', 'INVALID_TYPE'],
    'not an address' => [['email' => 'mona'], '/email', 'INVALID_TYPE'],
    'an address over 254 characters' => [['email' => str_repeat('a', 250).'@example.test'], '/email', 'OUT_OF_RANGE'],
    'a missing email' => [['email' => null], '/email', 'REQUIRED'],
    'a screenshot of another type' => [['screenshot' => ['media_type' => 'image/gif', 'data' => 'R0lGODlh']], '/screenshot/media_type', 'INVALID_ENUM_VALUE'],
    'a screenshot that is not base64' => [['screenshot' => ['media_type' => 'image/png', 'data' => '!!not base64!!']], '/screenshot/data', 'INVALID_TYPE'],
    'a screenshot of the wrong type' => [['screenshot' => ['media_type' => 'image/png', 'data' => '__jpeg__']], '/screenshot/data', 'INVALID_TYPE'],
    'a screenshot over 3 MiB' => [['screenshot' => ['media_type' => 'image/png', 'data' => '__big__']], '/screenshot/data', 'OUT_OF_RANGE'],
    'a screenshot without data' => [['screenshot' => ['media_type' => 'image/png']], '/screenshot/data', 'REQUIRED'],
]);

test('missing members are REQUIRED and unknown members are UNKNOWN_FIELD', function () {
    $body = claimBody();
    unset($body['reference'], $body['currency']);

    $response = claimResponse([...$body, 'note' => 'hello', 'screenshot' => ['media_type' => 'image/png', 'data' => receiptBase64(), 'name' => 'a.png']])
        ->assertValidResponse(422);

    $errors = collect($response->json('errors'))->map(fn ($e) => [$e['pointer'], $e['code']])->all();
    expect($errors)->toContain(['/reference', 'REQUIRED'])
        ->toContain(['/currency', 'REQUIRED'])
        ->toContain(['/note', 'UNKNOWN_FIELD'])
        ->toContain(['/screenshot/name', 'UNKNOWN_FIELD']);
});

test('several faults are listed together', function () {
    $response = claimResponse(claimBody(['amount_minor' => 1, 'currency' => 'USD', 'reference' => 'x', 'email' => 'nope']))->assertValidResponse(422);

    expect(collect($response->json('errors'))->map(fn ($e) => $e['pointer'])->sort()->values()->all())
        ->toBe(['/amount_minor', '/currency', '/email', '/reference']);
});

test('the idempotency key is required and must be well formed', function () {
    $this->withHeaders(['X-Client-Platform' => 'web', 'X-Client-Version' => '1.0.0', 'Accept' => 'application/json'])
        ->postJson('/api/v1/billing/instapay/claims', claimBody())
        ->assertValidResponse(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

    claimResponse(claimBody(), ['Idempotency-Key' => 'short'])->assertValidResponse(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
});

test('the same key and body replay the stored 202; another body is 409 IDEMPOTENCY_KEY_REUSED', function () {
    $headers = claimHeaders('form-submission-0001');

    $first = $this->withHeaders($headers)->postJson('/api/v1/billing/instapay/claims', claimBody())->assertValidResponse(202);
    $replay = $this->withHeaders($headers)->postJson('/api/v1/billing/instapay/claims', claimBody())
        ->assertValidResponse(202)->assertHeader('Idempotency-Replayed', 'true');
    $reused = $this->withHeaders($headers)->postJson('/api/v1/billing/instapay/claims', claimBody(['reference' => 'OTHER0000001']))
        ->assertValidResponse(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

    expect($replay->json())->toEqual($first->json())
        ->and($replay->json('meta'))->toBe([])
        ->and(PaymentClaim::query()->count())->toBe(1)
        ->and($reused->status())->toBe(409);
});

test('a refused submission releases its key, so a corrected one can use it', function () {
    $headers = claimHeaders('form-submission-0002');

    $this->withHeaders($headers)->postJson('/api/v1/billing/instapay/claims', claimBody(['amount_minor' => 1]))->assertValidResponse(422);
    $this->withHeaders($headers)->postJson('/api/v1/billing/instapay/claims', claimBody())->assertValidResponse(202)->assertHeaderMissing('Idempotency-Replayed');
});

test('the sixth valid submission from one origin in an hour is 429 with Retry-After', function () {
    foreach (range(1, 5) as $n) {
        claimResponse(claimBody(['email' => "payer{$n}@example.test", 'reference' => "REFERENCE000{$n}"]))->assertStatus(202);
    }

    $response = claimResponse(claimBody(['email' => 'payer6@example.test', 'reference' => 'REFERENCE0006']))->assertValidResponse(429);

    expect($response->json('code'))->toBe('RATE_LIMITED')
        ->and($response->headers->get('Retry-After'))->not->toBeNull()
        ->and($response->headers->get('RateLimit-Limit'))->toBe('5')
        ->and($response->headers->get('RateLimit-Remaining'))->toBe('0')
        ->and($response->headers->get('RateLimit-Reset'))->not->toBeNull()
        ->and(PaymentClaim::query()->count())->toBe(5);
});

test('the sixth valid submission for one address in 24 hours is 429, whoever sends it', function () {
    foreach (range(1, 5) as $n) {
        RateLimiter::clear('billing-claim-origin:127.0.0.1');
        claimResponse(claimBody(['reference' => "REFERENCE000{$n}"]))->assertStatus(202);
    }

    RateLimiter::clear('billing-claim-origin:127.0.0.1');
    claimResponse(claimBody(['reference' => 'REFERENCE0006']))->assertValidResponse(429);

    RateLimiter::clear('billing-claim-origin:127.0.0.1');
    claimResponse(claimBody(['email' => 'someone.else@example.test', 'reference' => 'REFERENCE0007']))->assertStatus(202);
});

test('a refused or replayed submission does not spend the budget', function () {
    foreach (range(1, 6) as $n) {
        claimResponse(claimBody(['amount_minor' => $n]))->assertStatus(422);
    }

    $headers = claimHeaders('form-submission-0003');
    foreach (range(1, 6) as $ignored) {
        $this->withHeaders($headers)->postJson('/api/v1/billing/instapay/claims', claimBody())->assertStatus(202);
    }

    foreach (range(1, 4) as $n) {
        claimResponse(claimBody(['email' => "other{$n}@example.test", 'reference' => "REFERENCE100{$n}"]))->assertStatus(202);
    }

    expect(PaymentClaim::query()->count())->toBe(5);
});

test('the shared refusals: not acceptable, unsupported media type, malformed JSON, upgrade required', function () {
    $uri = '/api/v1/billing/instapay/claims';
    $headers = fn (array $extra = []) => $this->transformHeadersToServerVars([...claimHeaders(), ...$extra]);

    $this->call('POST', $uri, [], [], [], $headers(['Accept' => 'text/html']), '{}')->assertStatus(406);
    $this->call('POST', $uri, [], [], [], $headers(['Content-Type' => 'text/plain']), 'x=1')->assertStatus(415);
    $this->call('POST', $uri, [], [], [], $headers(['Content-Type' => 'application/json']), '{not json')->assertStatus(400)->assertJsonPath('code', 'MALFORMED_JSON');
    $this->withHeaders([...claimHeaders(), 'X-Client-Version' => '0.0.1'])->postJson($uri, claimBody())->assertStatus(426);
});

test('a bearer credential makes no difference to a claim', function () {
    person('ada@example.test');
    $credential = signInAs('ada@example.test')->json('data.credential');

    $this->withHeaders([...asCredential($credential), ...claimHeaders('form-submission-0004')])
        ->postJson('/api/v1/billing/instapay/claims', claimBody(['email' => 'ada@example.test']))
        ->assertValidResponse(202);
});

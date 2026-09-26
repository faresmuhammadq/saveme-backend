<?php

use Illuminate\Support\Facades\Queue;

/*
 * POST /auth/register (spec 007, US1): 202 for a new address, 409 for one
 * that already has an account (owner decision 2026-09-27).
 */

beforeEach(fn () => Queue::fake());

test('a new address is accepted', function () {
    $this->withHeaders(jsonHeaders())
        ->postJson('/api/v1/auth/register', ['email' => 'new@example.test', 'password' => 'correct horse'])
        ->assertValidRequest()
        ->assertValidResponse(202)
        ->assertHeader('X-Request-Id')
        ->assertExactJson(['data' => ['status' => 'accepted'], 'meta' => []]);
});

test('an existing address is refused with EMAIL_ALREADY_REGISTERED', function () {
    person('taken@example.test');

    $this->withHeaders(jsonHeaders(['Accept-Language' => 'ar']))
        ->postJson('/api/v1/auth/register', ['email' => 'Taken@Example.test', 'password' => 'correct horse'])
        ->assertValidRequest()
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'EMAIL_ALREADY_REGISTERED')
        ->assertJsonPath('detail', trans('problems.EMAIL_ALREADY_REGISTERED', [], 'ar'));
    Queue::assertNothingPushed();
});

test('invalid input is refused with field codes', function (array $body, string $pointer, string $code) {
    $response = $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/register', $body);

    $response->assertValidResponse(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    expect(collect($response->json('errors'))->firstWhere('pointer', $pointer)['code'] ?? null)->toBe($code);
})->with([
    'unknown member' => [['email' => 'a@example.test', 'password' => 'correct horse', 'role' => 'admin'], '/role', 'UNKNOWN_FIELD'],
    'short password' => [['email' => 'a@example.test', 'password' => 'short'], '/password', 'OUT_OF_RANGE'],
    'unknown timezone' => [['email' => 'a@example.test', 'password' => 'correct horse', 'timezone' => 'Mars/Base'], '/timezone', 'INVALID_ENUM_VALUE'],
    'missing email' => [['password' => 'correct horse'], '/email', 'REQUIRED'],
]);

test('the eleventh registration from one origin in an hour is refused', function () {
    foreach (range(1, 10) as $attempt) {
        $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/register', ['email' => "p{$attempt}@example.test", 'password' => 'correct horse'])->assertStatus(202);
    }

    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/register', ['email' => 'p11@example.test', 'password' => 'correct horse'])
        ->assertValidResponse(429)
        ->assertJsonPath('code', 'RATE_LIMITED')
        ->assertHeader('Retry-After')
        ->assertHeader('RateLimit-Reset');
});

<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * POST /auth/login (spec 007, US1, US5).
 */

beforeEach(function () {
    Route::middleware(['api', 'auth:api'])->prefix('api/v1')->get('_whoami', fn () => response()->json(['id' => auth()->id()]));
});

test('a correct password returns a credential, the session, the account and the plan', function () {
    $user = person(verified: false);

    $response = signInAs()->assertValidResponse(200);

    expect($response->json('data.credential'))->toMatch('/^[0-9a-f-]{36}\|[A-Za-z0-9]{64}$/')
        ->and($response->json('data.session.current'))->toBeTrue()
        ->and($response->json('data.session.user_id'))->toBe($user->id)
        ->and($response->json('data.account.email'))->toBe('ada@example.test')
        ->and($response->json('data.account.email_verified'))->toBeFalse()
        ->and($response->json('data.entitlements.plan.name'))->toBe('Free')
        ->and($response->json('data.entitlements.features')['sharing.enabled'])->toBeFalse()
        ->and($response->json('data.deletion_cancelled'))->toBeFalse()
        ->and($response->json('data.session'))->not->toHaveKey('device_id');
});

test('the credential authenticates later requests; a garbage one does not', function () {
    $user = person();
    $credential = signInAs()->json('data.credential');

    $this->withHeaders(asCredential($credential))->get('/api/v1/_whoami')->assertOk()->assertJsonPath('id', $user->id);
    $this->flushHeaders();
    $this->withHeaders(asCredential($credential.'x'))->get('/api/v1/_whoami')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('a wrong password and an unknown address answer identically', function () {
    person();

    $wrong = signInAs(password: 'not the password')->assertValidResponse(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    $unknown = signInAs(email: 'nobody@example.test')->assertValidResponse(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');

    expect(collect($wrong->json())->except('request_id')->all())->toBe(collect($unknown->json())->except('request_id')->all());
});

test("another account's session id is not found and discloses nothing", function () {
    person('other@example.test');
    $theirs = deviceBody();
    signInAs('other@example.test', device: $theirs)->assertOk();
    person();

    signInAs(device: deviceBody(['session_id' => $theirs['session_id']]))
        ->assertValidResponse(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

test('an unsupported platform or a missing device is refused', function (array $body) {
    person();

    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'correct horse', ...$body])
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
})->with([
    'ios' => [['device' => deviceBody(['platform' => 'ios'])]],
    'no device' => [[]],
    'not a UUIDv7' => [['device' => deviceBody(['session_id' => (string) Str::uuid()])]],
]);

test('a locked address is 423 with Retry-After', function () {
    person();
    foreach (range(1, 5) as $attempt) {
        signInAs(password: 'not the password');
    }

    signInAs()->assertValidResponse(423)->assertJsonPath('code', 'ACCOUNT_LOCKED')->assertHeader('Retry-After');
});

test('an origin spraying failures is 429', function () {
    foreach (range(1, 20) as $n) {
        signInAs("spray{$n}@example.test", 'wrong');
    }

    signInAs('spray21@example.test', 'wrong')->assertValidResponse(429)->assertJsonPath('code', 'RATE_LIMITED');
});

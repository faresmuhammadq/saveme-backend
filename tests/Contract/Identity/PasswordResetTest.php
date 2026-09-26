<?php

use App\Modules\Identity\Domain\Account\LinkKind;
use Illuminate\Support\Facades\Queue;

/*
 * POST /auth/forgot-password and /auth/reset-password (spec 007, US4).
 */

beforeEach(fn () => Queue::fake());

test('a reset request answers identically for a registered and an unregistered address', function () {
    person();

    $registered = $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.test'])
        ->assertValidRequest()
        ->assertValidResponse(202);
    $unknown = $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])
        ->assertValidResponse(202);

    expect($unknown->getContent())->toBe($registered->getContent());
});

test('a malformed reset request is refused', function () {
    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/forgot-password', [])->assertValidResponse(422);
});

test('the fourth request for one address within an hour is refused, registered or not', function (string $email) {
    if ($email === 'ada@example.test') {
        person();
    }

    foreach (range(1, 3) as $attempt) {
        $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(202);
    }

    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/forgot-password', ['email' => $email])
        ->assertValidResponse(429)
        ->assertJsonPath('code', 'RATE_LIMITED');
})->with(['ada@example.test', 'nobody@example.test']);

test('a live reset link sets the password', function () {
    $user = person();

    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/reset-password', ['token' => linkFor($user->id, LinkKind::ResetPassword), 'password' => 'a new password'])
        ->assertValidRequest()
        ->assertValidResponse(204);

    signInAs(password: 'a new password')->assertOk();
});

test('a used, expired, superseded or unknown link is refused', function (string $case) {
    $user = person();
    $secret = linkFor($user->id, LinkKind::ResetPassword);

    match ($case) {
        'used' => $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/reset-password', ['token' => $secret, 'password' => 'a new password'])->assertNoContent(),
        'expired' => $this->travel(61)->minutes(),
        'superseded' => linkFor($user->id, LinkKind::ResetPassword),
        'unknown' => $secret = 'not-a-real-secret',
    };

    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/reset-password', ['token' => $secret, 'password' => 'another password'])
        ->assertValidResponse(400)
        ->assertJsonPath('code', 'RESET_TOKEN_INVALID');
})->with(['used', 'expired', 'superseded', 'unknown']);

test('a new password that breaks the policy is refused', function () {
    $user = person();

    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/reset-password', ['token' => linkFor($user->id, LinkKind::ResetPassword), 'password' => 'short'])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.code', 'OUT_OF_RANGE');
});

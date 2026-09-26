<?php

use App\Modules\Identity\Domain\Account\LinkKind;
use Illuminate\Support\Facades\Mail;

/*
 * POST /auth/verify-email and /auth/resend-verification (spec 007, US2).
 */

beforeEach(fn () => Mail::fake());

test('a live link verifies the address', function () {
    $user = person(verified: false);

    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/verify-email', ['token' => linkFor($user->id, LinkKind::VerifyAddress)])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.email_verified', true);
});

test('a used, expired, superseded or unknown link is refused', function (string $case) {
    $user = person(verified: false);
    $secret = linkFor($user->id, LinkKind::VerifyAddress);

    match ($case) {
        'used' => $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/verify-email', ['token' => $secret])->assertOk(),
        'expired' => $this->travel(25)->hours(),
        'superseded' => linkFor($user->id, LinkKind::VerifyAddress),
        'unknown' => $secret = 'not-a-real-secret',
    };

    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/verify-email', ['token' => $secret])
        ->assertValidResponse(400)
        ->assertJsonPath('code', 'VERIFICATION_TOKEN_INVALID');
})->with(['used', 'expired', 'superseded', 'unknown']);

test('a missing token is a validation failure', function () {
    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/verify-email', [])
        ->assertValidResponse(422);
});

test('a signed-in person can ask for the mail again, three times an hour', function () {
    person(verified: false);
    $credential = signInAs()->json('data.credential');

    foreach (range(1, 3) as $attempt) {
        $this->withHeaders(asCredential($credential))->postJson('/api/v1/auth/resend-verification')->assertValidResponse(202);
        $this->flushHeaders();
    }

    $this->withHeaders(asCredential($credential))->postJson('/api/v1/auth/resend-verification')
        ->assertValidResponse(429)
        ->assertJsonPath('code', 'RATE_LIMITED');
});

test('asking for the mail again needs a session', function () {
    $this->withHeaders(jsonHeaders())->postJson('/api/v1/auth/resend-verification')
        ->assertValidResponse(401)
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

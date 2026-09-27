<?php

use App\Modules\Identity\Application\VerificationGate;
use App\Modules\Identity\Domain\Refusals\EmailNotVerified;
use Illuminate\Support\Facades\Route;

/*
 * The verification gate (security baseline): an unverified address may read,
 * manage its profile and its sessions, but may not write to a wallet.
 * Other modules apply it as the alias `address.verified` (spec 007, R-17).
 */

beforeEach(function () {
    Route::middleware(['api', 'auth:api', 'address.verified'])->prefix('api/v1')->post('_gated', fn () => response()->json(['ok' => true]));
});

test('an unverified address is refused with its own code', function () {
    person(verified: false);

    $this->withHeaders(asCredential(signInAs()->json('data.credential')))->postJson('/api/v1/_gated')
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

test('a verified address passes', function () {
    person();

    $this->withHeaders(asCredential(signInAs()->json('data.credential')))->postJson('/api/v1/_gated')->assertOk();
});

test('session management is not behind the gate', function () {
    person(verified: false);

    $this->withHeaders(asCredential(signInAs()->json('data.credential')))->get('/api/v1/auth/sessions')->assertOk();
});

test('the application check refuses an unverified person', function () {
    $user = person(verified: false);

    expect(fn () => app(VerificationGate::class)->assertVerified($user->id))->toThrow(EmailNotVerified::class);
});

test('reading and saving one\'s own profile is not behind the gate', function () {
    person(verified: false);
    $credential = signInAs()->json('data.credential');

    $profile = $this->withHeaders(asCredential($credential))->get('/api/v1/me')->assertOk()->json('data.profile');
    $this->flushHeaders();

    $this->withHeaders(asCredential($credential))->putJson('/api/v1/me/profile', [
        'display_name' => 'Ada', 'locale' => 'en', 'timezone' => 'Africa/Cairo',
        'default_currency' => 'EGP', 'budget_period_start_day' => 1, 'version' => $profile['version'],
    ])->assertOk();
});

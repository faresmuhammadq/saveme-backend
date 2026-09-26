<?php

use App\Modules\Identity\Infrastructure\Database\Factories\DeviceSessionFactory;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/*
 * 90 days of inactivity, extended on use; the credential stored only as a
 * hash (security baseline, Device sessions; spec 007, R-3).
 */

beforeEach(function () {
    Route::middleware(['api', 'auth:api'])->prefix('api/v1')->get('_whoami', fn () => response()->json(['id' => auth()->id()]));
});

test('using a credential records the use without touching version or change_seq', function () {
    $session = DeviceSession::factory()->create();

    $this->withHeaders(asCredential(DeviceSessionFactory::credentialFor($session)))->get('/api/v1/_whoami')->assertOk();

    $fresh = $session->fresh();
    expect($fresh->last_used_at)->not->toBeNull()
        ->and($fresh->version)->toBe($session->version)
        ->and($fresh->change_seq)->toBe($session->change_seq);
});

test('use extends the window; 90 idle days end it', function () {
    $session = DeviceSession::factory()->create();
    $credential = DeviceSessionFactory::credentialFor($session);

    $this->travel(89)->days();
    $this->withHeaders(asCredential($credential))->get('/api/v1/_whoami')->assertOk();
    $this->flushHeaders();

    $this->travel(89)->days();
    $this->withHeaders(asCredential($credential))->get('/api/v1/_whoami')->assertOk();
    $this->flushHeaders();

    $this->travel(91)->days();
    $this->withHeaders(asCredential($credential))->get('/api/v1/_whoami')->assertStatus(401);
});

test('an idle session is refused even before the sweep marks it', function () {
    $session = DeviceSession::factory()->idleFor(91)->create();

    $this->withHeaders(asCredential(DeviceSessionFactory::credentialFor($session)))->get('/api/v1/_whoami')->assertStatus(401);
});

test('only the hash of the secret is stored', function () {
    person();
    $credential = signInAs()->json('data.credential');
    [$id, $secret] = explode('|', $credential);

    expect(DeviceSession::query()->find($id)->token)->toBe(hash('sha256', $secret));
});

test('a password hashed at a lower cost is upgraded on sign-in', function () {
    $weak = password_hash('correct horse', PASSWORD_ARGON2ID, ['memory_cost' => 512, 'time_cost' => 1, 'threads' => 1]);
    person();
    User::query()->update(['password_hash' => $weak]);

    signInAs()->assertOk();

    $stored = User::query()->sole()->password_hash;
    expect($stored)->not->toBe($weak)
        ->and(Hash::needsRehash($stored))->toBeFalse();
});

test('the daily sweep records idle sessions as expired, and the list shows it', function () {
    person();
    $idle = DeviceSession::factory()->idleFor(91)->create(['user_id' => User::query()->sole()->id]);
    $credential = signInAs()->json('data.credential');

    $this->artisan('identity:expire-sessions')->assertSuccessful();

    $fresh = $idle->fresh();
    expect($fresh->state)->toBe('expired')
        ->and($fresh->end_reason)->toBe('expired')
        ->and($fresh->version)->toBe($idle->version + 1);
    $listed = collect($this->withHeaders(asCredential($credential))->get('/api/v1/auth/sessions')->json('data'))->firstWhere('id', $idle->id);
    expect($listed['state'])->toBe('expired');
});

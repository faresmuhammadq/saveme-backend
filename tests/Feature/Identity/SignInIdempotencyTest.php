<?php

use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use Illuminate\Support\Facades\Route;

/*
 * Sign-in is idempotent per session id, and a device holds one active
 * session (spec 007, FR-022, R-4).
 */

beforeEach(function () {
    Route::middleware(['api', 'auth:api'])->prefix('api/v1')->get('_whoami', fn () => response()->json(['id' => auth()->id()]));
    person();
});

test('a retry with the same session id rotates that session', function () {
    $device = deviceBody();
    $first = signInAs(device: $device)->json('data.credential');
    $second = signInAs(device: $device)->json('data.credential');

    $session = DeviceSession::query()->sole();
    expect($session->id)->toBe($device['session_id'])
        ->and($session->version)->toBe(2)
        ->and($second)->not->toBe($first);

    $this->withHeaders(asCredential($first))->get('/api/v1/_whoami')->assertStatus(401);
    $this->flushHeaders();
    $this->withHeaders(asCredential($second))->get('/api/v1/_whoami')->assertOk();
});

test('a new session id from the same device replaces the earlier session', function () {
    $device = deviceBody();
    signInAs(device: $device);
    signInAs(device: deviceBody(['device_id' => $device['device_id']]));

    $earlier = DeviceSession::query()->find($device['session_id']);
    expect($earlier->state)->toBe('revoked')
        ->and($earlier->end_reason)->toBe('replaced')
        ->and(DeviceSession::query()->where('device_id', $device['device_id'])->where('state', 'active')->count())->toBe(1);
});

test("signing in with one's own ended session id is not found", function () {
    $device = deviceBody();
    signInAs(device: $device);
    DeviceSession::query()->whereKey($device['session_id'])->update(['state' => 'revoked', 'end_reason' => 'signed_out', 'ended_at' => now()]);

    signInAs(device: $device)->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
});

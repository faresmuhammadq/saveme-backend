<?php

use App\Modules\Identity\Infrastructure\Models\DeviceSession;

/*
 * A session identifier owned by another account is never affected or
 * disclosed (P-06 rule 3; spec 007, FR-023, FR-027).
 */

test('signing in with another account\'s session id leaves that session untouched', function () {
    person('a@example.test');
    person('b@example.test');
    $device = deviceBody();
    signInAs('a@example.test', device: $device)->assertOk();
    $before = DeviceSession::query()->find($device['session_id'])->only(['token', 'version', 'state', 'user_id']);

    signInAs('b@example.test', device: deviceBody(['session_id' => $device['session_id']]))->assertStatus(404);

    expect(DeviceSession::query()->find($device['session_id'])->only(['token', 'version', 'state', 'user_id']))->toBe($before);
});

test('ending another account\'s session is not found and leaves it untouched', function () {
    person('a@example.test');
    person('b@example.test');
    $theirs = signInAs('a@example.test')->json('data.session.id');
    $mine = signInAs('b@example.test')->json('data.credential');
    $before = DeviceSession::query()->find($theirs)->only(['token', 'version', 'state']);

    $this->withHeaders(asCredential($mine))->deleteJson("/api/v1/auth/sessions/{$theirs}")->assertStatus(404);

    expect(DeviceSession::query()->find($theirs)->only(['token', 'version', 'state']))->toBe($before);
});

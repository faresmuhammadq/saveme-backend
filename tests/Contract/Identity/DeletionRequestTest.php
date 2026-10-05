<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * GET/POST/DELETE /account/deletion-request (spec 007, spec 022): the plan,
 * the request with successors, the cancel.
 */

beforeEach(function () {
    Mail::fake();
    [$this->user, $this->credential] = walletCaller();
    $this->pat = person('pat@example.test');
    $this->quinn = person('quinn@example.test');
    $this->shared = ownedWallet($this->user->id);
    addMember($this->shared->id, $this->pat->id, 'viewer');
    addMember($this->shared->id, $this->quinn->id, 'editor');
});

test('getAccountDeletionPlan: 200 for an active account and for a pending one', function () {
    $active = exportCall('GET', $this->credential, '/account/deletion-request')->assertValidRequest()->assertValidResponse(200);
    expect($active->json('data.state'))->toBe('active')->and($active->json('data.successors.0.choice_expected'))->toBeTrue();

    exportCall('POST', $this->credential, '/account/deletion-request', ['password' => 'correct horse'])->assertValidResponse(200);

    $pending = exportCall('GET', $this->credential, '/account/deletion-request')->assertValidResponse(200);
    expect($pending->json('data.state'))->toBe('pending_deletion')->and($pending->json('data.deletion_scheduled_for'))->not->toBeNull();
});

test('getAccountDeletionPlan: the shared refusals', function () {
    assertSharedRefusals('GET', '/account/deletion-request', [], $this->credential);
});

test('requestAccountDeletion: 200 with the password only, as spec 007 had it, and with successors', function () {
    exportCall('POST', $this->credential, '/account/deletion-request', ['password' => 'correct horse'])
        ->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('data.successors.0.reason', 'oldest_editor');

    exportCall('POST', $this->credential, '/account/deletion-request', [
        'password' => 'correct horse',
        'successors' => [['wallet_id' => $this->shared->id, 'member_user_id' => $this->pat->id]],
    ])->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('data.successors.0.reason', 'chosen')
        ->assertJsonPath('data.successors.0.successor_user_id', $this->pat->id);
});

test('requestAccountDeletion: 401 for a wrong password, 422 at the entry, 423 when locked', function () {
    exportCall('POST', $this->credential, '/account/deletion-request', ['password' => 'not it'])
        ->assertValidResponse(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');

    exportCall('POST', $this->credential, '/account/deletion-request', [
        'password' => 'correct horse',
        'successors' => [['wallet_id' => $this->shared->id, 'member_user_id' => (string) Str::uuid7()]],
    ])->assertValidRequest()->assertValidResponse(422)->assertJsonPath('errors.0.pointer', '/successors/0/member_user_id');

    foreach (range(1, 5) as $attempt) {
        exportCall('POST', $this->credential, '/account/deletion-request', ['password' => 'not it']);
    }

    exportCall('POST', $this->credential, '/account/deletion-request', ['password' => 'correct horse'])
        ->assertValidResponse(423)->assertJsonPath('code', 'ACCOUNT_LOCKED');
});

test('requestAccountDeletion: the shared refusals', function () {
    assertSharedRefusals('POST', '/account/deletion-request', ['password' => 'correct horse'], $this->credential);
});

test('cancelAccountDeletion: 204, and the choices are gone', function () {
    exportCall('POST', $this->credential, '/account/deletion-request', [
        'password' => 'correct horse',
        'successors' => [['wallet_id' => $this->shared->id, 'member_user_id' => $this->pat->id]],
    ])->assertValidResponse(200);

    exportCall('DELETE', $this->credential, '/account/deletion-request')->assertValidResponse(204);

    expect(exportCall('GET', $this->credential, '/account/deletion-request')->json('data.successors.0.reason'))->toBe('oldest_editor');
});

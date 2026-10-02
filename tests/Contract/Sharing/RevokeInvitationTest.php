<?php

use App\Modules\Sharing\Infrastructure\Models\Invitation;

/*
 * DELETE /wallets/{id}/invitations/{iid} (spec 016, US3; row S-06): every
 * documented status.
 */

beforeEach(function () {
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
    $this->invitation = pendingInvitation($this->owner->id, $this->wallet->id, 'invitee@example.test');
    $this->uri = '/wallets/'.$this->wallet->id.'/invitations/'.$this->invitation['id'];
});

test('200 returns the tombstone at version + 1 with the address erased', function () {
    $response = walletCall('DELETE', $this->credential, $this->uri, ['version' => 1])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data'))->toMatchArray(['id' => $this->invitation['id'], 'status' => 'revoked', 'version' => 2, 'invitee_email' => null])
        ->and($response->json('data.deleted_at'))->not->toBeNull()
        ->and($response->json('meta'))->toBe([]);
});

test('200 again for an already closed invitation, unchanged', function () {
    $first = walletCall('DELETE', $this->credential, $this->uri, ['version' => 1])->assertOk();

    $again = walletCall('DELETE', $this->credential, $this->uri, ['version' => 1])->assertValidResponse(200);

    expect($again->json('data'))->toBe($first->json('data'));
});

test('403 for an unverified owner, an editor and a viewer; 404 for a non-member', function () {
    foreach (['editor', 'viewer'] as $role) {
        addMember($this->wallet->id, person("{$role}@example.test")->id, $role);
        $credential = signInAs("{$role}@example.test")->json('data.credential');
        walletCall('DELETE', $credential, $this->uri, ['version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    [, $stranger] = walletCaller('stranger@example.test');
    walletCall('DELETE', $stranger, $this->uri, ['version' => 1])->assertValidResponse(404);

    [$unverified, $unverifiedCredential] = walletCaller('unverified@example.test', verified: false);
    $theirs = ownedWallet($unverified->id);
    walletCall('DELETE', $unverifiedCredential, '/wallets/'.$theirs->id.'/invitations/'.$this->invitation['id'], ['version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    expect(Invitation::query()->findOrFail($this->invitation['id'])->status)->toBe('pending');
});

test('404 for an unknown invitation, another wallet\'s and a malformed-but-valid foreign id', function () {
    $other = ownedWallet($this->owner->id);

    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/'.Str::uuid7(), ['version' => 1])->assertValidResponse(404);
    walletCall('DELETE', $this->credential, '/wallets/'.$other->id.'/invitations/'.$this->invitation['id'], ['version' => 1])->assertValidResponse(404);
    expect(Invitation::query()->findOrFail($this->invitation['id'])->status)->toBe('pending');
});

test('409 for a stale version, with the stored invitation in current', function () {
    $response = walletCall('DELETE', $this->credential, $this->uri, ['version' => 4])->assertValidResponse(409);

    expect($response->json('code'))->toBe('VERSION_CONFLICT')
        ->and($response->json('current'))->toMatchArray(['id' => $this->invitation['id'], 'status' => 'pending', 'version' => 1]);
});

test('422 for a missing or malformed version and a malformed id; the body is required', function () {
    $missing = walletCall('DELETE', $this->credential, $this->uri, [])->assertValidResponse(422);
    expect(collect($missing->json('errors'))->firstWhere('pointer', '/version')['code'])->toBe('REQUIRED');

    walletCall('DELETE', $this->credential, $this->uri, ['version' => 0])->assertValidResponse(422);
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/invitations/not-an-id', ['version' => 1])->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, malformed JSON, upgrade required', function () {
    assertSharedRefusals('DELETE', $this->uri, ['version' => 1], $this->credential);
});

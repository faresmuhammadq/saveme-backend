<?php

use App\Modules\Wallets\Infrastructure\Models\WalletMember;

/*
 * POST /wallets/{id}/leave (spec 016, US6; FR-018; row S-05): every documented
 * status.
 */

beforeEach(function () {
    [$this->owner, $this->ownerCredential, $this->wallet] = plusOwner();
    $this->karim = person('karim@example.test');
    $this->member = addMember($this->wallet->id, $this->karim->id, 'editor');
    $this->karimCredential = signInAs('karim@example.test')->json('data.credential');
    $this->uri = '/wallets/'.$this->wallet->id.'/leave';
});

test('200 returns the leaver\'s tombstone at version + 1, for an editor', function () {
    $response = walletCall('POST', $this->karimCredential, $this->uri, ['version' => 1])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data'))->toMatchArray(['id' => $this->member->id, 'member_user_id' => $this->karim->id, 'version' => 2, 'role' => 'editor'])
        ->and($response->json('data.deleted_at'))->not->toBeNull()
        ->and($response->json('meta'))->toBe([]);
});

test('200 for a viewer, and for an unverified member: leaving puts nothing into the wallet', function () {
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $viewer->id, 'viewer');
    walletCall('POST', signInAs('viewer@example.test')->json('data.credential'), $this->uri, ['version' => 1])->assertValidResponse(200);

    $unverified = person('unverified@example.test', verified: false);
    addMember($this->wallet->id, $unverified->id, 'editor');
    walletCall('POST', signInAs('unverified@example.test')->json('data.credential'), $this->uri, ['version' => 1])->assertValidResponse(200);

    expect(WalletMember::query()->where('wallet_id', $this->wallet->id)->whereNull('deleted_at')->count())->toBe(2);
});

test('a repeated leave is 404: the caller is no longer a member', function () {
    walletCall('POST', $this->karimCredential, $this->uri, ['version' => 1])->assertOk();

    walletCall('POST', $this->karimCredential, $this->uri, ['version' => 2])->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
});

test('403 FORBIDDEN for the owner: ownership moves first', function () {
    walletCall('POST', $this->ownerCredential, $this->uri, ['version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');

    expect(WalletMember::query()->where('wallet_id', $this->wallet->id)->where('role', 'owner')->whereNull('deleted_at')->count())->toBe(1);
});

test('404 for a non-member and for a deleted wallet', function () {
    [, $stranger] = walletCaller('stranger@example.test');
    walletCall('POST', $stranger, $this->uri, ['version' => 1])->assertValidResponse(404);

    walletCall('DELETE', $this->ownerCredential, '/wallets/'.$this->wallet->id, ['version' => 1])->assertOk();
    walletCall('POST', $this->karimCredential, $this->uri, ['version' => 2])->assertValidResponse(404);
});

test('409 for a stale membership version, with the membership in current', function () {
    $response = walletCall('POST', $this->karimCredential, $this->uri, ['version' => 5])->assertValidResponse(409);

    expect($response->json('current'))->toMatchArray(['id' => $this->member->id, 'version' => 1, 'role' => 'editor']);
    expect(WalletMember::query()->findOrFail($this->member->id)->deleted_at)->toBeNull();
});

test('422 for a missing version and a malformed id', function () {
    $missing = walletCall('POST', $this->karimCredential, $this->uri, [])->assertValidResponse(422);
    expect(collect($missing->json('errors'))->firstWhere('pointer', '/version')['code'])->toBe('REQUIRED');

    walletCall('POST', $this->karimCredential, '/wallets/not-an-id/leave', ['version' => 1])->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, malformed JSON, upgrade required', function () {
    assertSharedRefusals('POST', $this->uri, ['version' => 1], $this->karimCredential);
});

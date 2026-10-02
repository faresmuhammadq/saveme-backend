<?php

use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;

/*
 * DELETE /wallets/{id}/members/{uid} (spec 016, US5; FR-017; row S-04): every
 * documented status.
 */

beforeEach(function () {
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
    $this->karim = person('karim@example.test');
    $this->member = addMember($this->wallet->id, $this->karim->id, 'viewer');
    $this->uri = '/wallets/'.$this->wallet->id.'/members/'.$this->karim->id;
});

test('200 returns the tombstone at version + 1', function () {
    $response = walletCall('DELETE', $this->credential, $this->uri, ['version' => 1])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data'))->toMatchArray(['id' => $this->member->id, 'member_user_id' => $this->karim->id, 'version' => 2, 'role' => 'viewer'])
        ->and($response->json('data.deleted_at'))->not->toBeNull()
        ->and($response->json('meta'))->toBe([]);
});

test('200 again for a repeat, and for a person who already left, with that tombstone', function () {
    $first = walletCall('DELETE', $this->credential, $this->uri, ['version' => 1])->assertOk();

    $again = walletCall('DELETE', $this->credential, $this->uri, ['version' => 1])->assertValidResponse(200);
    expect($again->json('data'))->toBe($first->json('data'));

    $left = person('left@example.test');
    $leftMember = addMember($this->wallet->id, $left->id, 'editor');
    WalletMember::query()->whereKey($leftMember->id)->update(['deleted_at' => now(), 'version' => 2]);
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/members/'.$left->id, ['version' => 2])->assertValidResponse(200)->assertJsonPath('data.version', 2);
});

test('the removed person\'s feed carries the tombstone, then the wallet is 404 for them', function () {
    $karim = signInAs('karim@example.test')->json('data.credential');
    walletCall('DELETE', $this->credential, $this->uri, ['version' => 1])->assertOk();

    $row = collect(readFeedFully($karim, 'user')['rows'])->where('type', 'wallet_member')->where('record.wallet_id', $this->wallet->id)->first();
    expect($row['record']['deleted_at'])->not->toBeNull();

    walletCall('GET', $karim, '/wallets/'.$this->wallet->id)->assertNotFound();
    readFeed($karim, $this->wallet->id)->assertNotFound();
    walletCall('GET', $karim, '/wallets/'.$this->wallet->id.'/transactions')->assertNotFound();
});

test('403 for the owner removing themselves, an editor, a viewer and an unverified owner', function () {
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/members/'.$this->owner->id, ['version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');

    $editor = person('editor@example.test');
    addMember($this->wallet->id, $editor->id, 'editor');
    foreach (['editor@example.test', 'karim@example.test'] as $email) {
        $credential = signInAs($email)->json('data.credential');
        walletCall('DELETE', $credential, $this->uri, ['version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    [$unverified, $unverifiedCredential] = walletCaller('unverified@example.test', verified: false);
    $theirs = ownedWallet($unverified->id);
    walletCall('DELETE', $unverifiedCredential, '/wallets/'.$theirs->id.'/members/'.$this->karim->id, ['version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

test('404 for a non-member caller and a person who was never a member', function () {
    [, $stranger] = walletCaller('stranger@example.test');
    walletCall('DELETE', $stranger, $this->uri, ['version' => 1])->assertValidResponse(404);

    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/members/'.person('never@example.test')->id, ['version' => 1])->assertValidResponse(404);
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/members/'.Str::uuid7(), ['version' => 1])->assertValidResponse(404);
});

test('409 for a stale version, with the membership in current', function () {
    $response = walletCall('DELETE', $this->credential, $this->uri, ['version' => 3])->assertValidResponse(409);

    expect($response->json('current'))->toMatchArray(['id' => $this->member->id, 'version' => 1]);
    expect(WalletMember::query()->findOrFail($this->member->id)->deleted_at)->toBeNull();
});

test('422 for a missing version and a malformed id', function () {
    $missing = walletCall('DELETE', $this->credential, $this->uri, [])->assertValidResponse(422);
    expect(collect($missing->json('errors'))->firstWhere('pointer', '/version')['code'])->toBe('REQUIRED');

    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/members/not-an-id', ['version' => 1])->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, malformed JSON, upgrade required', function () {
    assertSharedRefusals('DELETE', $this->uri, ['version' => 1], $this->credential);
});

test('removal needs no entitlement: an owner whose plan lapsed can still remove', function () {
    Subscription::query()->where('user_id', $this->owner->id)->delete();
    app()->forgetScopedInstances();

    walletCall('DELETE', $this->credential, $this->uri, ['version' => 1])->assertValidResponse(200);
});

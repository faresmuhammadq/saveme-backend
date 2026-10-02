<?php

use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;

/*
 * PATCH /wallets/{id}/members/{uid} (spec 016, US5; FR-016; rows S-03): every
 * documented status.
 */

beforeEach(function () {
    [$this->owner, $this->credential, $this->wallet] = plusOwner();
    $this->karim = person('karim@example.test');
    $this->member = addMember($this->wallet->id, $this->karim->id, 'editor');
    $this->uri = '/wallets/'.$this->wallet->id.'/members/'.$this->karim->id;
});

test('200 lowers an editor to viewer, version + 1, and the live row rides both feeds', function () {
    $response = walletCall('PATCH', $this->credential, $this->uri, ['role' => 'viewer', 'version' => 1])->assertValidRequest()->assertValidResponse(200);

    expect($response->json('data'))->toMatchArray(['id' => $this->member->id, 'member_user_id' => $this->karim->id, 'role' => 'viewer', 'version' => 2, 'deleted_at' => null])
        ->and($response->json('meta'))->toBe([]);

    $karim = signInAs('karim@example.test')->json('data.credential');
    $ownFeed = collect(readFeedFully($karim, 'user')['rows'])->where('type', 'wallet_member')->where('record.wallet_id', $this->wallet->id)->first();
    $walletFeed = collect(readFeedFully($this->credential, $this->wallet->id)['rows'])->where('type', 'wallet_member')->where('record.member_user_id', $this->karim->id)->first();
    expect($ownFeed['record']['role'])->toBe('viewer')->and($walletFeed['record'])->toBe($ownFeed['record']);
});

test('200 raises a viewer to editor while the plan allows it', function () {
    WalletMember::query()->whereKey($this->member->id)->update(['role' => 'viewer']);

    walletCall('PATCH', $this->credential, $this->uri, ['role' => 'editor', 'version' => 1])->assertValidResponse(200)->assertJsonPath('data.role', 'editor');
});

test('200 unchanged when the role is already the one sent, whatever the version', function () {
    $response = walletCall('PATCH', $this->credential, $this->uri, ['role' => 'editor', 'version' => 7])->assertValidResponse(200);

    expect($response->json('data.version'))->toBe(1)->and($response->json('data.change_seq'))->toBe($this->member->change_seq);
});

test('403 EMAIL_NOT_VERIFIED for an unverified owner; FORBIDDEN for an editor and a viewer', function () {
    [$unverified, $unverifiedCredential] = walletCaller('unverified@example.test', verified: false);
    $theirs = ownedWallet($unverified->id);
    $someone = person('someone@example.test');
    addMember($theirs->id, $someone->id, 'editor');
    walletCall('PATCH', $unverifiedCredential, '/wallets/'.$theirs->id.'/members/'.$someone->id, ['role' => 'viewer', 'version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $viewer->id, 'viewer');
    foreach (['karim@example.test', 'viewer@example.test'] as $email) {
        $credential = signInAs($email)->json('data.credential');
        walletCall('PATCH', $credential, $this->uri, ['role' => 'viewer', 'version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');
    }
});

test('403 FEATURE_NOT_ENTITLED when raising without sharing or past the quota; lowering is never refused', function () {
    WalletMember::query()->whereKey($this->member->id)->update(['role' => 'viewer']);
    foreach (range(1, 4) as $n) {
        addMember($this->wallet->id, person("m{$n}@example.test")->id, 'viewer');
    }
    // Owner + Karim + 4 = 6 active members, above the Plus quota of 5.
    $over = walletCall('PATCH', $this->credential, $this->uri, ['role' => 'editor', 'version' => 1])->assertValidResponse(403);
    expect($over->json('code'))->toBe('FEATURE_NOT_ENTITLED')->and($over->json('feature'))->toBe('sharing.max_members_per_wallet');

    Subscription::query()->where('user_id', $this->owner->id)->delete();
    app()->forgetScopedInstances();
    $none = walletCall('PATCH', $this->credential, $this->uri, ['role' => 'editor', 'version' => 1])->assertValidResponse(403);
    expect($none->json('feature'))->toBe('sharing.enabled');

    WalletMember::query()->whereKey($this->member->id)->update(['role' => 'editor']);
    walletCall('PATCH', $this->credential, $this->uri, ['role' => 'viewer', 'version' => 1])->assertValidResponse(200);
});

test('404 for a non-member caller and for a uid that is not an active member', function () {
    [, $stranger] = walletCaller('stranger@example.test');
    walletCall('PATCH', $stranger, $this->uri, ['role' => 'viewer', 'version' => 1])->assertValidResponse(404);

    $nobody = person('nobody@example.test');
    walletCall('PATCH', $this->credential, '/wallets/'.$this->wallet->id.'/members/'.$nobody->id, ['role' => 'viewer', 'version' => 1])->assertValidResponse(404);
    walletCall('PATCH', $this->credential, '/wallets/'.$this->wallet->id.'/members/'.Str::uuid7(), ['role' => 'viewer', 'version' => 1])->assertValidResponse(404);

    WalletMember::query()->whereKey($this->member->id)->update(['deleted_at' => now()]);
    walletCall('PATCH', $this->credential, $this->uri, ['role' => 'viewer', 'version' => 1])->assertValidResponse(404);
});

test('409 for a stale version, with the membership in current', function () {
    $response = walletCall('PATCH', $this->credential, $this->uri, ['role' => 'viewer', 'version' => 9])->assertValidResponse(409);

    expect($response->json('code'))->toBe('VERSION_CONFLICT')
        ->and($response->json('current'))->toMatchArray(['id' => $this->member->id, 'role' => 'editor', 'version' => 1, 'member_user_id' => $this->karim->id]);
    expect(WalletMember::query()->findOrFail($this->member->id)->role)->toBe('editor');
});

test('422 IMMUTABLE_FIELD at /role for owner, and for the owner member', function () {
    $toOwner = walletCall('PATCH', $this->credential, $this->uri, ['role' => 'owner', 'version' => 1])->assertValidResponse(422);
    $ownerSelf = walletCall('PATCH', $this->credential, '/wallets/'.$this->wallet->id.'/members/'.$this->owner->id, ['role' => 'viewer', 'version' => 1])->assertValidResponse(422);

    foreach ([$toOwner, $ownerSelf] as $response) {
        expect($response->json('code'))->toBe('VALIDATION_FAILED')
            ->and($response->json('errors.0'))->toMatchArray(['pointer' => '/role', 'code' => 'IMMUTABLE_FIELD']);
    }
    expect(WalletMember::query()->findOrFail($this->member->id)->role)->toBe('editor');
});

test('422 for a bad role, a missing member, a malformed id and an unknown field', function () {
    $bad = walletCall('PATCH', $this->credential, $this->uri, ['role' => 'admin', 'version' => 1])->assertValidResponse(422);
    expect($bad->json('errors.0'))->toMatchArray(['pointer' => '/role', 'code' => 'INVALID_ENUM_VALUE']);

    $missing = walletCall('PATCH', $this->credential, $this->uri, [])->assertValidResponse(422);
    expect(collect($missing->json('errors'))->pluck('code', 'pointer')->all())->toBe(['/role' => 'REQUIRED', '/version' => 'REQUIRED']);

    walletCall('PATCH', $this->credential, '/wallets/'.$this->wallet->id.'/members/not-an-id', ['role' => 'viewer', 'version' => 1])->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
    walletCall('PATCH', $this->credential, $this->uri, ['role' => 'viewer', 'version' => 1, 'display_name' => 'x'])->assertStatus(422);
});

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, malformed JSON, upgrade required', function () {
    assertSharedRefusals('PATCH', $this->uri, ['role' => 'viewer', 'version' => 1], $this->credential);
});

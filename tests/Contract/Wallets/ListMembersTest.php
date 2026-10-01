<?php

use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Str;

/*
 * GET /wallets/{id}/members (spec 010, US4).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->grace = person('grace@example.test');
    addMember($this->wallet->id, $this->grace->id, 'editor');
});

test('lists active members in creation order with display name and role', function () {
    walletCall('PUT', $this->credential, '/me/profile', []);
    $profile = Profile::query()->where('user_id', $this->grace->id)->firstOrFail();
    $profile->display_name_ciphertext = app(AttributeCipher::class)->seal($this->grace->id, 'Grace');
    $profile->save();

    $response = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/members')
        ->assertValidRequest()
        ->assertValidResponse(200);

    $members = $response->json('data');
    expect($members)->toHaveCount(2)
        ->and($members[0])->toMatchArray(['member_user_id' => $this->user->id, 'role' => 'owner', 'display_name' => null, 'wallet_id' => $this->wallet->id])
        ->and($members[1])->toMatchArray(['member_user_id' => $this->grace->id, 'role' => 'editor', 'display_name' => 'Grace'])
        ->and($response->json('meta.has_more'))->toBeFalse();
});

test('exposes nothing but the contract members: no address, no user attributes', function () {
    $response = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/members')->assertValidResponse(200);

    expect(array_keys($response->json('data.0')))->toBe(['id', 'wallet_id', 'version', 'change_seq', 'created_at', 'updated_at', 'deleted_at', 'member_user_id', 'display_name', 'role']);
    expect($response->getContent())->not->toContain('grace@example.test')->not->toContain('email');
});

test('a removed member is not listed', function () {
    WalletMember::query()->where('user_id', $this->grace->id)->update(['deleted_at' => now()]);

    expect(walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/members')->assertValidResponse(200)->json('data'))->toHaveCount(1);
});

test('paging with limit visits every member', function () {
    $first = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/members?limit=1')->assertValidResponse(200);
    expect($first->json('meta.has_more'))->toBeTrue();

    $second = walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/members?limit=1&cursor='.$first->json('meta.next_cursor'))->assertValidResponse(200);

    expect($second->json('data.0.member_user_id'))->toBe($this->grace->id)
        ->and($second->json('meta.has_more'))->toBeFalse();
});

test('a viewer may read the members', function () {
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $viewer->id, 'viewer');
    $credential = signInAs('viewer@example.test')->json('data.credential');

    walletCall('GET', $credential, '/wallets/'.$this->wallet->id.'/members')->assertValidResponse(200);
});

test('a non-member is 404, a malformed id 422, sort is unsupported, anonymous is 401', function () {
    $stranger = person('stranger@example.test');
    $strangerCredential = signInAs('stranger@example.test')->json('data.credential');

    walletCall('GET', $strangerCredential, '/wallets/'.$this->wallet->id.'/members')->assertValidResponse(404);
    walletCall('GET', $this->credential, '/wallets/'.Str::uuid7().'/members')->assertValidResponse(404);
    walletCall('GET', $this->credential, '/wallets/nope/members')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/members?sort=created_at')->assertValidResponse(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/wallets/'.$this->wallet->id.'/members')->assertValidResponse(401);
});

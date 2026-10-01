<?php

use Illuminate\Support\Str;

/*
 * GET /wallets/{id} (spec 010, US1).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
});

test('a member reads the wallet with their role', function () {
    $other = person('grace@example.test');
    $wallet = ownedWallet($other->id, ['name' => 'Shared']);
    addMember($wallet->id, $this->user->id, 'editor');

    walletCall('GET', $this->credential, '/wallets/'.$wallet->id)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.name', 'Shared')
        ->assertJsonPath('data.my_role', 'editor');
});

test('a non-member and an unknown id are 404', function () {
    $other = person('grace@example.test');
    $wallet = ownedWallet($other->id);

    walletCall('GET', $this->credential, '/wallets/'.$wallet->id)->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
    walletCall('GET', $this->credential, '/wallets/'.Str::uuid7())->assertValidResponse(404);
});

test('a malformed id is 422 INVALID_ID', function () {
    walletCall('GET', $this->credential, '/wallets/not-an-id')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('a former member reads the tombstone, a stranger gets 404', function () {
    $stranger = person('stranger@example.test');
    $strangerCredential = signInAs('stranger@example.test')->json('data.credential');
    $wallet = ownedWallet($this->user->id);
    walletCall('DELETE', $this->credential, '/wallets/'.$wallet->id, ['version' => 1])->assertOk();

    walletCall('GET', $this->credential, '/wallets/'.$wallet->id)
        ->assertValidResponse(200)
        ->assertJsonPath('data.deleted_at', fn ($at) => $at !== null)
        ->assertJsonPath('data.my_role', 'owner');
    walletCall('GET', $strangerCredential, '/wallets/'.$wallet->id)->assertValidResponse(404);
});

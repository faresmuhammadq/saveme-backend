<?php

use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Str;

/*
 * Every wallet write is behind the verification gate; reads are not
 * (spec 010, FR-021; security baseline C1).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller('ada@example.test', verified: false);
    $this->wallet = ownedWallet($this->user->id);
});

test('an unverified person is 403 EMAIL_NOT_VERIFIED on every write', function (string $method, string $uri, array $body) {
    walletCall($method, $this->credential, str_replace('{id}', $this->wallet->id, $uri), $body)
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
})->with([
    'put' => ['PUT', '/wallets/{id}', walletBody()],
    'patch' => ['PATCH', '/wallets/{id}', ['name' => 'X', 'version' => 1]],
    'delete' => ['DELETE', '/wallets/{id}', ['version' => 1]],
    'archive' => ['POST', '/wallets/{id}/archive', ['version' => 1]],
    'transfer' => ['POST', '/wallets/{id}/transfer-ownership', ['user_id' => '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70', 'version' => 1]],
]);

test('the gate answers before the id is judged', function () {
    walletCall('PUT', $this->credential, '/wallets/not-an-id', walletBody())->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

test('an unverified person can create nothing', function () {
    walletCall('PUT', $this->credential, '/wallets/'.Str::uuid7(), walletBody())->assertStatus(403);

    expect(Wallet::query()->count())->toBe(1);
});

test('reads work for an unverified person', function () {
    walletCall('GET', $this->credential, '/wallets')->assertOk();
    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id)->assertOk();
    walletCall('GET', $this->credential, '/wallets/'.$this->wallet->id.'/members')->assertOk();
});

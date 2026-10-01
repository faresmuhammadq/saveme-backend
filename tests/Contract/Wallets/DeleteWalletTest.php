<?php

use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Testing\TestResponse;

/*
 * DELETE /wallets/{id} carries its version in a JSON body (spec 010, US3, R-5).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
});

function deleteWalletCall(string $credential, string $id, array $body = ['version' => 1]): TestResponse
{
    return walletCall('DELETE', $credential, '/wallets/'.$id, $body);
}

test('deleting returns the tombstone', function () {
    $response = deleteWalletCall($this->credential, $this->wallet->id)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2);

    expect($response->json('data.deleted_at'))->not->toBeNull()
        ->and(Wallet::query()->find($this->wallet->id))->not->toBeNull();
});

test('deleting again is 200 with the same tombstone', function () {
    $first = deleteWalletCall($this->credential, $this->wallet->id)->json('data');

    deleteWalletCall($this->credential, $this->wallet->id)
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', $first['version'])
        ->assertJsonPath('data.deleted_at', $first['deleted_at']);
});

test('a stale version is 409 with the current wallet', function () {
    walletCall('PATCH', $this->credential, '/wallets/'.$this->wallet->id, ['name' => 'New', 'version' => 1])->assertOk();

    deleteWalletCall($this->credential, $this->wallet->id)
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.name', 'New');
});

test('a missing version or no body at all is 422 REQUIRED at /version, not 415', function (array $body) {
    $response = deleteWalletCall($this->credential, $this->wallet->id, $body)->assertStatus(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/version', 'code' => 'REQUIRED']);
})->with(['empty object' => [[]]]);

test('no body and no content type is 422 REQUIRED, not 415', function () {
    $this->call('DELETE', '/api/v1/wallets/'.$this->wallet->id, [], [], [], $this->transformHeadersToServerVars(asCredential($this->credential)))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.pointer', '/version')
        ->assertJsonPath('errors.0.code', 'REQUIRED');
});

test('a body that is not JSON is 415', function () {
    $this->call('DELETE', '/api/v1/wallets/'.$this->wallet->id, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Content-Type' => 'text/plain']), 'version=1')
        ->assertStatus(415)
        ->assertJsonPath('code', 'UNSUPPORTED_MEDIA_TYPE');
});

test('an editor and a viewer are 403, a non-member 404', function () {
    $owner = person('grace@example.test');
    $shared = ownedWallet($owner->id);
    $private = ownedWallet($owner->id);
    addMember($shared->id, $this->user->id, 'viewer');

    deleteWalletCall($this->credential, $shared->id)->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');
    deleteWalletCall($this->credential, $private->id)->assertValidResponse(404);

    expect(Wallet::query()->find($shared->id)->deleted_at)->toBeNull();
});

test('an identical write to a tombstone is 409, never 200', function () {
    deleteWalletCall($this->credential, $this->wallet->id)->assertOk();

    walletCall('PUT', $this->credential, '/wallets/'.$this->wallet->id, walletBody(['name' => $this->wallet->name, 'opening_balance_minor' => 0, 'icon' => null, 'color' => null], version: 2))
        ->assertValidResponse(409);
    walletCall('PATCH', $this->credential, '/wallets/'.$this->wallet->id, ['name' => $this->wallet->name, 'version' => 2])
        ->assertValidResponse(409);
    walletCall('POST', $this->credential, '/wallets/'.$this->wallet->id.'/archive', ['version' => 2])
        ->assertValidResponse(409);
});

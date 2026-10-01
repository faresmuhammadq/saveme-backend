<?php

use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Testing\TestResponse;

/*
 * POST /wallets/{id}/archive (spec 010, US3).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id, ['opening_balance_minor' => 500, 'balance_minor' => 500]);
});

function archiveWallet(string $credential, string $id, array $body): TestResponse
{
    return walletCall('POST', $credential, '/wallets/'.$id.'/archive', $body);
}

test('archiving sets archived_at and leaves the balance and members alone', function () {
    $response = archiveWallet($this->credential, $this->wallet->id, ['version' => 1])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.balance_minor', 500);

    expect($response->json('data.archived_at'))->not->toBeNull()
        ->and(WalletMember::query()->where('wallet_id', $this->wallet->id)->whereNull('deleted_at')->count())->toBe(1);
});

test('archiving again is 200 with nothing changed', function () {
    $first = archiveWallet($this->credential, $this->wallet->id, ['version' => 1])->json('data');

    archiveWallet($this->credential, $this->wallet->id, ['version' => 1])
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', $first['version'])
        ->assertJsonPath('data.change_seq', $first['change_seq'])
        ->assertJsonPath('data.archived_at', $first['archived_at']);
});

test('a stale version is 409 with the current wallet', function () {
    walletCall('PATCH', $this->credential, '/wallets/'.$this->wallet->id, ['name' => 'New', 'version' => 1])->assertOk();

    archiveWallet($this->credential, $this->wallet->id, ['version' => 1])
        ->assertValidResponse(409)
        ->assertJsonPath('current.name', 'New');
});

test('archiving a deleted wallet is 409 with the tombstone', function () {
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id, ['version' => 1])->assertOk();

    archiveWallet($this->credential, $this->wallet->id, ['version' => 2])->assertValidResponse(409);
});

test('a missing version is REQUIRED at /version', function () {
    $response = archiveWallet($this->credential, $this->wallet->id, [])->assertStatus(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/version', 'code' => 'REQUIRED']);
});

test('an editor is 403, a viewer is 403, a non-member 404', function () {
    $owner = person('grace@example.test');
    $shared = ownedWallet($owner->id);
    $private = ownedWallet($owner->id);
    addMember($shared->id, $this->user->id, 'editor');

    archiveWallet($this->credential, $shared->id, ['version' => 1])->assertValidResponse(403);
    archiveWallet($this->credential, $private->id, ['version' => 1])->assertValidResponse(404);
});

test('a malformed id is 422 INVALID_ID', function () {
    archiveWallet($this->credential, 'nope', ['version' => 1])->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

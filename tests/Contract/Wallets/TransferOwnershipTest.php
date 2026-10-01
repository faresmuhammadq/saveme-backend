<?php

use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Testing\TestResponse;

/*
 * POST /wallets/{id}/transfer-ownership (spec 010, US4).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->grace = person('grace@example.test');
    addMember($this->wallet->id, $this->grace->id, 'editor');
});

function transferCall(string $credential, string $walletId, array $body): TestResponse
{
    return walletCall('POST', $credential, '/wallets/'.$walletId.'/transfer-ownership', $body);
}

test('the transfer returns the wallet with my_role editor', function () {
    transferCall($this->credential, $this->wallet->id, ['user_id' => $this->grace->id, 'version' => 1])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.my_role', 'editor')
        ->assertJsonPath('data.version', 2);

    expect(WalletMember::query()->where('wallet_id', $this->wallet->id)->where('role', 'owner')->value('user_id'))->toBe($this->grace->id);
});

test('naming the person who already owns it is 200 unchanged, for the former owner too', function () {
    transferCall($this->credential, $this->wallet->id, ['user_id' => $this->grace->id, 'version' => 1])->assertOk();

    transferCall($this->credential, $this->wallet->id, ['user_id' => $this->grace->id, 'version' => 1])
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.my_role', 'editor');
});

test('naming oneself or a non-member is 422 INVALID_ENUM_VALUE at /user_id', function (string $who) {
    $stranger = person('stranger@example.test');
    $target = $who === 'self' ? $this->user->id : $stranger->id;

    $response = transferCall($this->credential, $this->wallet->id, ['user_id' => $target, 'version' => 1])->assertValidResponse(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/user_id', 'code' => 'INVALID_ENUM_VALUE']);
})->with(['self', 'stranger']);

test('a stale version is 409 with the current wallet', function () {
    walletCall('PATCH', $this->credential, '/wallets/'.$this->wallet->id, ['name' => 'New', 'version' => 1])->assertOk();

    transferCall($this->credential, $this->wallet->id, ['user_id' => $this->grace->id, 'version' => 1])
        ->assertValidResponse(409)
        ->assertJsonPath('current.name', 'New');
});

test('an editor is 403 and a non-member 404', function () {
    $graceCredential = signInAs('grace@example.test')->json('data.credential');
    $stranger = person('stranger@example.test');
    $strangerCredential = signInAs('stranger@example.test')->json('data.credential');

    transferCall($graceCredential, $this->wallet->id, ['user_id' => $this->grace->id, 'version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');
    transferCall($strangerCredential, $this->wallet->id, ['user_id' => $stranger->id, 'version' => 1])->assertValidResponse(404);
});

test('a missing user_id or version is REQUIRED, a malformed one INVALID_ID', function () {
    $missing = transferCall($this->credential, $this->wallet->id, ['version' => 1])->assertStatus(422);
    expect($missing->json('errors.0'))->toMatchArray(['pointer' => '/user_id', 'code' => 'REQUIRED']);

    $bad = transferCall($this->credential, $this->wallet->id, ['user_id' => 'x', 'version' => 1])->assertValidResponse(422);
    expect($bad->json('errors.0'))->toMatchArray(['pointer' => '/user_id', 'code' => 'INVALID_ID']);
});

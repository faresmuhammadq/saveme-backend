<?php

use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * PATCH /wallets/{id} (spec 010, US2).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id, ['name' => 'Cash', 'icon' => 'wallet', 'color' => '#2E7D32', 'opening_balance_minor' => 50000, 'balance_minor' => 50000]);
});

function patchWallet(string $credential, string $id, array $body): TestResponse
{
    return walletCall('PATCH', $credential, '/wallets/'.$id, $body);
}

test('a partial update leaves the absent members untouched', function () {
    patchWallet($this->credential, $this->wallet->id, ['name' => 'Spending', 'version' => 1])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.name', 'Spending')
        ->assertJsonPath('data.icon', 'wallet')
        ->assertJsonPath('data.color', '#2E7D32')
        ->assertJsonPath('data.version', 2);
});

test('icon and colour can be cleared with null', function () {
    patchWallet($this->credential, $this->wallet->id, ['icon' => null, 'color' => null, 'version' => 1])
        ->assertValidResponse(200)
        ->assertJsonPath('data.icon', null)
        ->assertJsonPath('data.color', null);
});

test('a stale version is 409 with the current wallet', function () {
    patchWallet($this->credential, $this->wallet->id, ['name' => 'One', 'version' => 1])->assertOk();

    patchWallet($this->credential, $this->wallet->id, ['name' => 'Two', 'version' => 1])
        ->assertValidResponse(409)
        ->assertJsonPath('current.name', 'One');
});

test('only the version is REQUIRED at the body root', function () {
    $response = patchWallet($this->credential, $this->wallet->id, ['version' => 1])->assertStatus(422);

    expect($response->json('code'))->toBe('VALIDATION_FAILED')
        ->and($response->json('errors.0'))->toMatchArray(['pointer' => '', 'code' => 'REQUIRED']);
});

test('a missing version is REQUIRED at /version', function () {
    $response = patchWallet($this->credential, $this->wallet->id, ['name' => 'X'])->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/version')['code'])->toBe('REQUIRED');
});

test('the type is not editable here', function () {
    $response = patchWallet($this->credential, $this->wallet->id, ['type' => 'savings', 'version' => 1])->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/type')['code'])->toBe('UNKNOWN_FIELD');
});

test('a blank name is OUT_OF_RANGE and an amount string is AMOUNT_NOT_INTEGER', function () {
    $blank = patchWallet($this->credential, $this->wallet->id, ['name' => ' ', 'version' => 1])->assertValidResponse(422);
    expect($blank->json('errors.0'))->toMatchArray(['pointer' => '/name', 'code' => 'OUT_OF_RANGE']);

    $amount = patchWallet($this->credential, $this->wallet->id, ['opening_balance_minor' => '1.5', 'version' => 1])->assertValidResponse(422);
    expect($amount->json('errors.0'))->toMatchArray(['pointer' => '/opening_balance_minor', 'code' => 'AMOUNT_NOT_INTEGER']);
});

test('an immutable currency is 422 IMMUTABLE_FIELD', function () {
    fakePostings(hasAny: true);

    $response = patchWallet($this->credential, $this->wallet->id, ['currency' => 'USD', 'version' => 1])->assertValidResponse(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/currency', 'code' => 'IMMUTABLE_FIELD']);
});

test('an editor is 403 and a non-member 404, and nothing changes', function () {
    $owner = person('grace@example.test');
    $shared = ownedWallet($owner->id, ['name' => 'Hers']);
    $private = ownedWallet($owner->id, ['name' => 'Private']);
    addMember($shared->id, $this->user->id, 'editor');

    patchWallet($this->credential, $shared->id, ['name' => 'X', 'version' => 1])->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');
    patchWallet($this->credential, $private->id, ['name' => 'X', 'version' => 1])->assertValidResponse(404);

    expect(Wallet::query()->find($shared->id)->name)->toBe('Hers')
        ->and(Wallet::query()->find($private->id)->name)->toBe('Private');
});

test('an unknown id is 404 and never creates', function () {
    patchWallet($this->credential, (string) Str::uuid7(), ['name' => 'X', 'version' => 1])->assertValidResponse(404);

    expect(Wallet::query()->count())->toBe(1);
});

test('a deleted wallet is 409 with the tombstone', function () {
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id, ['version' => 1])->assertOk();

    patchWallet($this->credential, $this->wallet->id, ['name' => 'X', 'version' => 2])
        ->assertValidResponse(409)
        ->assertJsonPath('current.deleted_at', fn ($at) => $at !== null);
});

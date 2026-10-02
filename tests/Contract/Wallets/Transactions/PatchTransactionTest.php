<?php

use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * PATCH /wallets/{id}/transactions/{tid} (spec 012, US3): an edit or a
 * restore, never a create.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    $this->id = (string) Str::uuid7();
    transactionCall('PUT', $this->credential, $this->wallet->id, $this->id, transactionBody())->assertCreated();
});

function patchTransaction(string $credential, string $walletId, string $id, array $body): TestResponse
{
    return transactionCall('PATCH', $credential, $walletId, $id, $body);
}

test('an edit is 200 and moves the version', function () {
    patchTransaction($this->credential, $this->wallet->id, $this->id, ['amount_minor' => 4000, 'memo' => 'note', 'version' => 1])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.amount_minor', 4000)
        ->assertJsonPath('data.memo', 'note');

    expect(walletBalance($this->wallet->id))->toBe(46000);
});

test('a description may be cleared with null', function () {
    patchTransaction($this->credential, $this->wallet->id, $this->id, ['description' => null, 'version' => 1])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.description', null);
});

test('an identical patch is 200 and changes nothing', function () {
    patchTransaction($this->credential, $this->wallet->id, $this->id, ['amount_minor' => 2500, 'version' => 1])
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 1);
});

test('a restore is 200, live again at version + 1', function () {
    transactionCall('DELETE', $this->credential, $this->wallet->id, $this->id, ['version' => 1])->assertOk();

    patchTransaction($this->credential, $this->wallet->id, $this->id, ['deleted_at' => null, 'version' => 2])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 3)
        ->assertJsonPath('data.deleted_at', null);

    expect(walletBalance($this->wallet->id))->toBe(47500);
});

test('a restore of a live transaction is 200 unchanged', function () {
    patchTransaction($this->credential, $this->wallet->id, $this->id, ['deleted_at' => null, 'version' => 1])
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 1);
});

test('only a version is REQUIRED at the body root and a missing version is REQUIRED at /version', function () {
    $root = patchTransaction($this->credential, $this->wallet->id, $this->id, ['version' => 1])->assertValidResponse(422);
    expect($root->json('errors'))->toHaveCount(1)->and($root->json('errors.0.pointer'))->toBe('')->and($root->json('errors.0.code'))->toBe('REQUIRED');

    $version = patchTransaction($this->credential, $this->wallet->id, $this->id, ['amount_minor' => 5])->assertStatus(422);
    expect(collect($version->json('errors'))->firstWhere('pointer', '/version')['code'])->toBe('REQUIRED');
});

test('kind, currency, counter_wallet_id and wallet_id are unknown fields', function (string $member, mixed $value) {
    $response = patchTransaction($this->credential, $this->wallet->id, $this->id, ['amount_minor' => 5, $member => $value, 'version' => 1])->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/'.$member)['code'])->toBe('UNKNOWN_FIELD');
})->with([['kind', 'income'], ['currency', 'USD'], ['counter_wallet_id', null], ['wallet_id', '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70']]);

test('a restore body with another member is unknown and a non-null deleted_at is INVALID_TYPE', function () {
    $extra = patchTransaction($this->credential, $this->wallet->id, $this->id, ['deleted_at' => null, 'amount_minor' => 5, 'version' => 1])->assertStatus(422);
    expect(collect($extra->json('errors'))->firstWhere('pointer', '/amount_minor')['code'])->toBe('UNKNOWN_FIELD');

    $value = patchTransaction($this->credential, $this->wallet->id, $this->id, ['deleted_at' => '2026-10-01T00:00:00.000000Z', 'version' => 1])->assertValidResponse(422);
    expect(collect($value->json('errors'))->firstWhere('pointer', '/deleted_at')['code'])->toBe('INVALID_TYPE');
});

test('an unknown id is 404 and nothing is created', function () {
    patchTransaction($this->credential, $this->wallet->id, (string) Str::uuid7(), ['amount_minor' => 5, 'version' => 1])->assertValidResponse(404);

    expect(Transaction::query()->count())->toBe(1);
});

test('a stale version and an edit of a tombstone are 409 with the current record', function () {
    patchTransaction($this->credential, $this->wallet->id, $this->id, ['amount_minor' => 3000, 'version' => 1])->assertOk();

    patchTransaction($this->credential, $this->wallet->id, $this->id, ['amount_minor' => 1, 'version' => 1])
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.amount_minor', 3000);

    transactionCall('DELETE', $this->credential, $this->wallet->id, $this->id, ['version' => 2])->assertOk();
    patchTransaction($this->credential, $this->wallet->id, $this->id, ['amount_minor' => 1, 'version' => 3])
        ->assertValidResponse(409)
        ->assertJsonPath('current.deleted_at', fn ($at) => $at !== null);
    patchTransaction($this->credential, $this->wallet->id, $this->id, ['deleted_at' => null, 'version' => 1])
        ->assertValidResponse(409);
});

test('a viewer is 403 and an invalid category is 422', function () {
    $owner = person('grace@example.test');
    $wallet = ownedWallet($owner->id);
    $tid = recordTransaction($owner->id, $wallet->id)->transaction->id;
    addMember($wallet->id, $this->user->id, 'viewer');

    patchTransaction($this->credential, $wallet->id, $tid, ['amount_minor' => 5, 'version' => 1])->assertValidResponse(403);

    $response = patchTransaction($this->credential, $this->wallet->id, $this->id, ['category_id' => (string) Str::uuid7(), 'version' => 1])->assertValidResponse(422);
    expect($response->json('errors.0.code'))->toBe('INVALID_ENUM_VALUE');
});

test('a malformed id is INVALID_ID', function () {
    patchTransaction($this->credential, $this->wallet->id, 'nope', ['amount_minor' => 5, 'version' => 1])->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

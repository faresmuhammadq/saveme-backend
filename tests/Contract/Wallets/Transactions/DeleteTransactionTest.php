<?php

use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;

/*
 * DELETE /wallets/{id}/transactions/{tid} (spec 012, US3 S5): a tombstone,
 * idempotent, with the version in a JSON body.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    $this->id = (string) Str::uuid7();
    transactionCall('PUT', $this->credential, $this->wallet->id, $this->id, transactionBody())->assertCreated();
});

test('a delete is 200 with the tombstone and the balance returns', function () {
    $response = transactionCall('DELETE', $this->credential, $this->wallet->id, $this->id, ['version' => 1])
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data.deleted_at'))->not->toBeNull()
        ->and($response->json('data.version'))->toBe(2)
        ->and(walletBalance($this->wallet->id))->toBe(50000)
        ->and(Transaction::query()->count())->toBe(1);
});

test('deleting twice returns the same tombstone', function () {
    $first = transactionCall('DELETE', $this->credential, $this->wallet->id, $this->id, ['version' => 1])->assertOk()->json('data');

    $second = transactionCall('DELETE', $this->credential, $this->wallet->id, $this->id, ['version' => 1])->assertValidResponse(200)->json('data');

    expect($second)->toBe($first);
});

test('a stale version is 409 with the current transaction', function () {
    transactionCall('DELETE', $this->credential, $this->wallet->id, $this->id, ['version' => 5])
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.version', 1);
});

test('a missing body is 422 REQUIRED at /version', function () {
    $response = transactionCall('DELETE', $this->credential, $this->wallet->id, $this->id)->assertValidResponse(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/version')['code'])->toBe('REQUIRED');
});

test('404 for an unknown id, another wallet\'s transaction and a non-member; 403 for a viewer', function () {
    $stranger = person('grace@example.test');
    $theirs = ownedWallet($stranger->id);
    $foreign = recordTransaction($stranger->id, $theirs->id)->transaction->id;

    transactionCall('DELETE', $this->credential, $this->wallet->id, (string) Str::uuid7(), ['version' => 1])->assertValidResponse(404);
    transactionCall('DELETE', $this->credential, $this->wallet->id, $foreign, ['version' => 1])->assertValidResponse(404);
    transactionCall('DELETE', $this->credential, $theirs->id, $foreign, ['version' => 1])->assertValidResponse(404);

    addMember($theirs->id, $this->user->id, 'viewer');
    transactionCall('DELETE', $this->credential, $theirs->id, $foreign, ['version' => 1])->assertValidResponse(403);
});

test('a transfer delete removes both effects', function () {
    $other = ownedWallet($this->user->id);
    $transfer = (string) Str::uuid7();
    transactionCall('PUT', $this->credential, $this->wallet->id, $transfer, transferBody($other->id, ['amount_minor' => 1000]))->assertCreated();

    transactionCall('DELETE', $this->credential, $other->id, $transfer, ['version' => 1])->assertValidResponse(200);

    expect(walletBalance($other->id))->toBe(0)->and(walletBalance($this->wallet->id))->toBe(47500);
});

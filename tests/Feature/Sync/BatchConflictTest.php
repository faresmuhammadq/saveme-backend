<?php

use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;

/*
 * A stale item is refused whole with the current record; nothing merges
 * (spec 013, US2; P-07).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->transaction = recordTransaction($this->user->id, $this->wallet->id)->transaction;
    $this->path = fn (array $changes, int $version) => syncItem('transaction', 'upsert', $this->transaction->id, transactionBody($changes, $version), $this->wallet->id);
});

test('a stale item is a conflict carrying the stored record, stores nothing of itself, and the other items apply', function () {
    saveTransaction($this->user->id, $this->wallet->id, $this->transaction->id, transactionBody(['description' => 'Edited elsewhere'], 1));
    $fresh = (string) Str::uuid7();

    $response = postBatch($this->credential, [
        ($this->path)(['description' => 'Mine', 'amount_minor' => 9999], 1),
        syncItem('transaction', 'upsert', $fresh, transactionBody(), $this->wallet->id),
    ])->assertOk();

    expect($response->json('data.0.outcome'))->toBe('conflict')
        ->and($response->json('data.0.status'))->toBe(409)
        ->and($response->json('data.0.problem.code'))->toBe('VERSION_CONFLICT')
        ->and($response->json('data.0.problem.current.description'))->toBe('Edited elsewhere')
        ->and($response->json('data.0.record'))->toBeNull()
        ->and($response->json('data.1.outcome'))->toBe('applied')
        ->and(Transaction::query()->findOrFail($this->transaction->id)->amount_minor)->toBe(2500)
        ->and(Transaction::query()->findOrFail($this->transaction->id)->description)->toBe('Edited elsewhere');
});

test('an item for a record deleted elsewhere conflicts with the tombstone', function () {
    walletCall('DELETE', $this->credential, '/wallets/'.$this->wallet->id.'/transactions/'.$this->transaction->id, ['version' => 1])->assertOk();

    $response = postBatch($this->credential, [($this->path)(['description' => 'Late edit'], 1)])->assertOk();

    expect($response->json('data.0.outcome'))->toBe('conflict')
        ->and($response->json('data.0.problem.current.deleted_at'))->not->toBeNull();
});

test('a refused item is rejected with the single write\'s problem, pointers rooted at data', function () {
    $response = postBatch($this->credential, [
        syncItem('transaction', 'upsert', (string) Str::uuid7(), transactionBody(['amount_minor' => '12.5']), $this->wallet->id),
        syncItem('transaction', 'upsert', (string) Str::uuid7(), transactionBody(), (string) Str::uuid7()),
    ])->assertOk();

    expect($response->json('data.*.outcome'))->toBe(['rejected', 'rejected'])
        ->and($response->json('data.0.status'))->toBe(422)
        ->and($response->json('data.0.problem.errors.0.pointer'))->toBe('/amount_minor')
        ->and($response->json('data.0.problem.errors.0.code'))->toBe('AMOUNT_NOT_INTEGER')
        ->and($response->json('data.1.status'))->toBe(404);
});

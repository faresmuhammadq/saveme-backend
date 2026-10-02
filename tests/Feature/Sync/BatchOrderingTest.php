<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Str;

/*
 * Items run one at a time in array order (spec 013, US2, A-2).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
});

test('a batch creating a wallet, a category and a transaction using both is fully applied, results in item order', function () {
    [$walletId, $categoryId, $transactionId] = [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];

    $response = postBatch($this->credential, [
        syncItem('wallet', 'upsert', $walletId, walletBody()),
        syncItem('category', 'upsert', $categoryId, categoryBody()),
        syncItem('transaction', 'upsert', $transactionId, transactionBody(['category_id' => $categoryId]), $walletId),
    ])->assertOk();

    expect($response->json('data.*.outcome'))->toBe(['applied', 'applied', 'applied'])
        ->and($response->json('data.*.status'))->toBe([201, 201, 201])
        ->and($response->json('data.*.id'))->toBe([$walletId, $categoryId, $transactionId])
        ->and($response->json('data.2.record.category_id'))->toBe($categoryId)
        ->and(Wallet::query()->whereKey($walletId)->exists())->toBeTrue()
        ->and(Category::query()->whereKey($categoryId)->exists())->toBeTrue()
        ->and(Transaction::query()->whereKey($transactionId)->exists())->toBeTrue()
        ->and(walletBalance($walletId))->toBe(50000 - 2500);
});

test('the same record twice: an identical second item is a replay, a different one with the old version is a conflict', function () {
    $walletId = (string) Str::uuid7();

    $replay = postBatch($this->credential, [
        syncItem('wallet', 'upsert', $walletId, walletBody()),
        syncItem('wallet', 'upsert', $walletId, walletBody()),
    ])->assertOk();

    expect($replay->json('data.*.outcome'))->toBe(['applied', 'applied'])
        ->and($replay->json('data.*.status'))->toBe([201, 200])
        ->and($replay->json('data.1.record.version'))->toBe(1);

    $clash = postBatch($this->credential, [
        syncItem('wallet', 'upsert', $walletId, walletBody(['name' => 'Renamed'], 1)),
        syncItem('wallet', 'upsert', $walletId, walletBody(['name' => 'Other'], 1)),
    ])->assertOk();

    expect($clash->json('data.*.outcome'))->toBe(['applied', 'conflict'])
        ->and($clash->json('data.1.problem.current.name'))->toBe('Renamed');
});

test('the parent request is intact after a batch: the response carries the batch request id and the batch locale', function () {
    $response = $this->withHeaders([...asCredential($this->credential), 'Accept-Language' => 'ar'])->postJson('/api/v1/sync/batch', ['items' => [
        syncItem('wallet', 'upsert', (string) Str::uuid7(), walletBody(['name' => ''])),
    ]])->assertOk();
    $this->flushHeaders();

    expect($response->headers->get('X-Request-Id'))->toBeUuidV7()
        ->and($response->json('data.0.problem.request_id'))->toBe($response->headers->get('X-Request-Id'))
        ->and($response->headers->get('Content-Language'))->toBe('ar')
        ->and($response->json('data.0.problem.errors.0.pointer'))->toBe('/name');
});

<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Resending a batch is safe: every item is idempotent by id and version
 * (spec 013, US2; ADR-0016 rule 7).
 */

test('resending the same batch creates nothing twice and moves no version, change_seq or balance', function () {
    [$user, $credential] = walletCaller();
    [$walletId, $categoryId, $transactionId] = [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];
    $items = [
        syncItem('wallet', 'upsert', $walletId, walletBody()),
        syncItem('category', 'upsert', $categoryId, categoryBody()),
        syncItem('transaction', 'upsert', $transactionId, transactionBody(['category_id' => $categoryId]), $walletId),
    ];

    $first = postBatch($credential, $items)->assertOk();
    $snapshot = fn (): array => [
        Wallet::query()->findOrFail($walletId)->only(['version', 'change_seq', 'balance_minor']),
        Category::query()->findOrFail($categoryId)->only(['version', 'change_seq']),
        Transaction::query()->findOrFail($transactionId)->only(['version', 'change_seq']),
        (int) DB::table('users')->where('id', $user->id)->value('change_seq'),
        Wallet::query()->count(), Category::query()->whereNotNull('user_id')->count(), Transaction::query()->count(),
    ];
    $before = $snapshot();

    foreach (range(1, 3) as $attempt) {
        $again = postBatch($credential, $items)->assertOk();

        expect($again->json('data.*.outcome'))->toBe(['applied', 'applied', 'applied'])
            ->and($again->json('data.*.status'))->toBe([200, 200, 200])
            ->and(array_map(fn (array $record): array => [$record['id'], $record['version']], $again->json('data.*.record')))
            ->toBe(array_map(fn (array $record): array => [$record['id'], $record['version']], $first->json('data.*.record')));
    }

    expect($snapshot())->toBe($before);
});

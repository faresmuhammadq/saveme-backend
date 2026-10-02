<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The ledger's shape is held by the database as well as the code (spec 012
 * section 4; ADR-0012 rule 3; P-04): a posting has exactly one target and is
 * never zero, a transfer has no category and a distinct counter wallet, an
 * amount is positive and bounded, a date is in range.
 */

beforeEach(function () {
    $this->wallet = Wallet::factory()->create();
    $this->other = Wallet::factory()->create();
    $this->category = Category::factory()->create();
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function transactionRow(string $walletId, ?string $categoryId, array $changes = []): array
{
    return [
        'id' => (string) Str::uuid7(), 'wallet_id' => $walletId, 'kind' => 'expense', 'date' => '2026-10-01',
        'amount_minor' => 100, 'currency' => 'EGP', 'category_id' => $categoryId, 'counter_wallet_id' => null,
        'description' => null, 'memo' => null, 'counterparty' => null, 'cleared' => false, 'created_by' => (string) Str::uuid7(),
        'version' => 1, 'change_seq' => 1, 'counter_change_seq' => null, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ...$changes,
    ];
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function postingRow(string $transactionId, array $changes = []): array
{
    return ['id' => (string) Str::uuid7(), 'transaction_id' => $transactionId, 'wallet_id' => null, 'category_id' => null, 'amount_minor' => -100, 'currency' => 'EGP', ...$changes];
}

function violatesAtTheDatabase(Closure $insert): bool
{
    try {
        DB::transaction($insert);
    } catch (QueryException $exception) {
        return $exception->getCode() === '23514';
    }

    return false;
}

test('valid rows insert', function () {
    $transfer = transactionRow($this->wallet->id, null, ['kind' => 'transfer', 'counter_wallet_id' => $this->other->id, 'counter_change_seq' => 1]);
    $expense = transactionRow($this->wallet->id, $this->category->id);

    DB::table('transactions')->insert([$transfer, $expense]);
    DB::table('postings')->insert([
        postingRow($expense['id'], ['wallet_id' => $this->wallet->id]),
        postingRow($expense['id'], ['category_id' => $this->category->id, 'amount_minor' => 100]),
    ]);

    expect(Transaction::query()->count())->toBe(2)->and(DB::table('postings')->count())->toBe(2);
});

test('a posting with both targets, neither, or a zero amount is refused', function () {
    $transaction = transactionRow($this->wallet->id, $this->category->id);
    DB::table('transactions')->insert($transaction);

    expect(violatesAtTheDatabase(fn () => DB::table('postings')->insert(postingRow($transaction['id'], ['wallet_id' => $this->wallet->id, 'category_id' => $this->category->id]))))->toBeTrue()
        ->and(violatesAtTheDatabase(fn () => DB::table('postings')->insert(postingRow($transaction['id']))))->toBeTrue()
        ->and(violatesAtTheDatabase(fn () => DB::table('postings')->insert(postingRow($transaction['id'], ['wallet_id' => $this->wallet->id, 'amount_minor' => 0]))))->toBeTrue();
});

test('a transfer with a category, an expense without one, or a counter wallet on an expense is refused', function () {
    expect(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, $this->category->id, ['kind' => 'transfer', 'counter_wallet_id' => $this->other->id, 'counter_change_seq' => 1]))))->toBeTrue()
        ->and(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, null))))->toBeTrue()
        ->and(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, $this->category->id, ['counter_wallet_id' => $this->other->id, 'counter_change_seq' => 1]))))->toBeTrue()
        ->and(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, null, ['kind' => 'transfer']))))->toBeTrue();
});

test('a counter wallet equal to its wallet, or a stamp out of step with it, is refused', function () {
    expect(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, null, ['kind' => 'transfer', 'counter_wallet_id' => $this->wallet->id, 'counter_change_seq' => 1]))))->toBeTrue()
        ->and(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, null, ['kind' => 'transfer', 'counter_wallet_id' => $this->other->id]))))->toBeTrue();
});

test('a non-positive or oversized amount and a date outside 1900 to 2999 are refused', function () {
    expect(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, $this->category->id, ['amount_minor' => 0]))))->toBeTrue()
        ->and(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, $this->category->id, ['amount_minor' => -5]))))->toBeTrue()
        ->and(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, $this->category->id, ['amount_minor' => 9007199254740992]))))->toBeTrue()
        ->and(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, $this->category->id, ['date' => '1899-12-31']))))->toBeTrue()
        ->and(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, $this->category->id, ['date' => '3000-01-01']))))->toBeTrue();
});

test('a blank description, memo or counterparty is refused', function () {
    foreach (['description', 'memo', 'counterparty'] as $column) {
        expect(violatesAtTheDatabase(fn () => DB::table('transactions')->insert(transactionRow($this->wallet->id, $this->category->id, [$column => '  ']))))->toBeTrue();
    }
});

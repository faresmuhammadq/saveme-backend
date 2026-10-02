<?php

use App\Modules\Wallets\Application\Transactions\Dto\SavedTransaction;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Str;

/*
 * Recording an expense or an income (spec 012, US1 S1-S3, FR-001 to FR-006).
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    $this->id = (string) Str::uuid7();
});

/**
 * @param  array<string, mixed>  $body
 */
function saveWithOutcome(string $userId, string $walletId, string $id, array $body): SavedTransaction
{
    return app(TransactionService::class)->save($userId, $walletId, $id, transactionValues($body), (int) $body['version'], presentTransaction());
}

test('an expense is created at version 1 and lowers the balance once', function () {
    $saved = saveWithOutcome($this->owner->id, $this->wallet->id, $this->id, transactionBody(['amount_minor' => 2500]));

    expect($saved->created)->toBeTrue()
        ->and($saved->view->transaction->version)->toBe(1)
        ->and($saved->view->transaction->kind->value)->toBe('expense')
        ->and($saved->view->transaction->walletId)->toBe($this->wallet->id)
        ->and($saved->view->transaction->createdBy)->toBe($this->owner->id)
        ->and(walletBalance($this->wallet->id))->toBe(47500);
    expectLedgerIntact();
});

test('an income raises the balance', function () {
    saveWithOutcome($this->owner->id, $this->wallet->id, $this->id, incomeBody(['amount_minor' => 10000]));

    expect(walletBalance($this->wallet->id))->toBe(60000);
    expectLedgerIntact();
});

test('a replay of the same id and body adds nothing and moves nothing', function () {
    saveWithOutcome($this->owner->id, $this->wallet->id, $this->id, transactionBody());
    $before = Wallet::query()->find($this->wallet->id);
    $row = Transaction::query()->find($this->id);

    $replay = saveWithOutcome($this->owner->id, $this->wallet->id, $this->id, transactionBody());

    $after = Wallet::query()->find($this->wallet->id);
    expect($replay->created)->toBeFalse()
        ->and(Transaction::query()->count())->toBe(1)
        ->and(Transaction::query()->find($this->id)->version)->toBe($row->version)
        ->and(Transaction::query()->find($this->id)->change_seq)->toBe($row->change_seq)
        ->and($after->change_seq)->toBe($before->change_seq)
        ->and($after->balance_minor)->toBe($before->balance_minor);
});

test('the recorder is created_by, whoever the editor is', function () {
    $editor = person('grace@example.test');
    addMember($this->wallet->id, $editor->id, 'editor');

    $view = recordTransaction($editor->id, $this->wallet->id, transactionBody());

    expect($view->transaction->createdBy)->toBe($editor->id);
});

test('text is stored trimmed and a blank one as null', function () {
    $view = recordTransaction($this->owner->id, $this->wallet->id, transactionBody(['description' => '  Lunch  ', 'memo' => '   ', 'counterparty' => ' Ahmed ']));

    expect($view->transaction->description)->toBe('Lunch')
        ->and($view->transaction->memo)->toBeNull()
        ->and($view->transaction->counterparty)->toBe('Ahmed');
});

test('an archived wallet and an archived category both accept a transaction', function () {
    $archived = ownedWallet($this->owner->id, ['archived_at' => now()]);
    $category = ownedCategory($this->owner->id, ['archived_at' => now()]);

    $view = recordTransaction($this->owner->id, $archived->id, transactionBody(['category_id' => $category->id]));

    expect($view->transaction->categoryId)->toBe($category->id);
});

test('a balance going negative is never refused', function () {
    recordTransaction($this->owner->id, $this->wallet->id, transactionBody(['amount_minor' => 80000]));

    expect(walletBalance($this->wallet->id))->toBe(-30000);
});

test('a create takes the transaction its own change_seq and leaves the wallet the highest', function () {
    recordTransaction($this->owner->id, $this->wallet->id, transactionBody());

    expectTransactionScopeIntact($this->wallet->id);
});

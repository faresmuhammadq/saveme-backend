<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionVersionConflict;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Str;

/*
 * Saving and patching a transaction (spec 012, US3 S1-S4, S7; FR-010 to
 * FR-013, P-07): versions, replays, immutables, whole-record conflicts.
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    $this->id = (string) Str::uuid7();
    $this->service = app(TransactionService::class);
    saveTransaction($this->owner->id, $this->wallet->id, $this->id, transactionBody(['amount_minor' => 2500]));
});

test('a whole save re-derives the postings and recomputes the balance', function () {
    $view = saveTransaction($this->owner->id, $this->wallet->id, $this->id, transactionBody(['amount_minor' => 4000, 'description' => 'Dinner'], version: 1));

    expect($view->transaction->version)->toBe(2)
        ->and($view->transaction->description)->toBe('Dinner')
        ->and(walletBalance($this->wallet->id))->toBe(46000)
        ->and(Posting::query()->where('transaction_id', $this->id)->count())->toBe(2);
    expectLedgerIntact();
});

test('a patch changes only the members sent', function () {
    $view = $this->service->patch($this->owner->id, $this->wallet->id, $this->id, ['memo' => 'with friends', 'cleared' => true], 1, presentTransaction());

    expect($view->transaction->memo)->toBe('with friends')
        ->and($view->transaction->cleared)->toBeTrue()
        ->and($view->transaction->amountMinor)->toBe(2500)
        ->and($view->transaction->description)->toBe('Lunch')
        ->and($view->transaction->version)->toBe(2);
});

test('a patch of the date and the category moves the transaction and keeps the balance', function () {
    $category = ownedCategory($this->owner->id);

    $view = $this->service->patch($this->owner->id, $this->wallet->id, $this->id, ['date' => '2026-09-15', 'category_id' => $category->id], 1, presentTransaction());

    expect($view->transaction->date)->toBe('2026-09-15')->and($view->transaction->categoryId)->toBe($category->id)->and(walletBalance($this->wallet->id))->toBe(47500);
    expectLedgerIntact();
});

test('a stale version is refused with the current transaction and nothing is written', function () {
    $this->service->patch($this->owner->id, $this->wallet->id, $this->id, ['amount_minor' => 3000], 1, presentTransaction());

    try {
        saveTransaction($this->owner->id, $this->wallet->id, $this->id, transactionBody(['amount_minor' => 9000], version: 1));
        $this->fail('expected a conflict');
    } catch (TransactionVersionConflict $conflict) {
        expect($conflict->errorCode)->toBe(ErrorCode::VersionConflict)
            ->and($conflict->extensions['current']['amount_minor'])->toBe(3000)
            ->and($conflict->extensions['current']['version'])->toBe(2)
            ->and(Transaction::query()->find($this->id)->amount_minor)->toBe(3000)
            ->and(walletBalance($this->wallet->id))->toBe(47000);
    }
});

test('a version 0 on an existing id that differs is a conflict, not an overwrite', function () {
    expect(refusalOf(fn () => saveTransaction($this->owner->id, $this->wallet->id, $this->id, transactionBody(['amount_minor' => 1]))))->toBeInstanceOf(TransactionVersionConflict::class);
});

test('an identical write or patch moves no version, change_seq, balance, reference or posting', function () {
    $before = [Transaction::query()->find($this->id)->only(['version', 'change_seq', 'updated_at']), Wallet::query()->find($this->wallet->id)->only(['change_seq', 'balance_minor']), Posting::query()->pluck('id')->all(), CategoryReference::query()->count()];

    saveTransaction($this->owner->id, $this->wallet->id, $this->id, transactionBody(['amount_minor' => 2500], version: 1));
    $this->service->patch($this->owner->id, $this->wallet->id, $this->id, ['amount_minor' => 2500, 'description' => ' Lunch '], 1, presentTransaction());

    $after = [Transaction::query()->find($this->id)->only(['version', 'change_seq', 'updated_at']), Wallet::query()->find($this->wallet->id)->only(['change_seq', 'balance_minor']), Posting::query()->pluck('id')->all(), CategoryReference::query()->count()];
    expect($after)->toEqual($before);
});

test('the kind never changes', function () {
    expect(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->wallet->id, $this->id, incomeBody(['amount_minor' => 2500], version: 1)))))
        ->toBe(['/kind IMMUTABLE_FIELD']);
});

test('a currency other than the wallet\'s is refused with a mismatch', function () {
    expect(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->wallet->id, $this->id, transactionBody(['currency' => 'USD'], version: 1)))))
        ->toBe(['/currency CURRENCY_MISMATCH']);
});

test('an editor keeps another member\'s category on an amount edit and is refused a third person\'s', function () {
    $editor = person('grace@example.test');
    $stranger = person('linus@example.test');
    addMember($this->wallet->id, $editor->id, 'editor');
    $ownersCategory = ownedCategory($this->owner->id);
    $theirs = ownedCategory($stranger->id);
    $mine = ownedCategory($editor->id);
    $this->service->patch($this->owner->id, $this->wallet->id, $this->id, ['category_id' => $ownersCategory->id], 1, presentTransaction());

    $kept = $this->service->patch($editor->id, $this->wallet->id, $this->id, ['amount_minor' => 100], 2, presentTransaction());
    expect($kept->transaction->categoryId)->toBe($ownersCategory->id)->and($kept->transaction->createdBy)->toBe($this->owner->id);

    expect(fieldErrorsOf(refusalOf(fn () => $this->service->patch($editor->id, $this->wallet->id, $this->id, ['category_id' => $theirs->id], 3, presentTransaction()))))
        ->toBe(['/category_id INVALID_ENUM_VALUE']);

    $switched = $this->service->patch($editor->id, $this->wallet->id, $this->id, ['category_id' => $mine->id], 3, presentTransaction());
    expect($switched->transaction->categoryId)->toBe($mine->id);
});

test('every edit takes a new change_seq and leaves the wallet the highest in its scope', function () {
    $first = Transaction::query()->find($this->id)->change_seq;

    $this->service->patch($this->owner->id, $this->wallet->id, $this->id, ['amount_minor' => 1], 1, presentTransaction());

    expect(Transaction::query()->find($this->id)->change_seq)->toBeGreaterThan($first);
    expectTransactionScopeIntact($this->wallet->id);
});

test('a patch never creates', function () {
    expect(refusalOf(fn () => $this->service->patch($this->owner->id, $this->wallet->id, (string) Str::uuid7(), ['amount_minor' => 1], 1, presentTransaction()))?->errorCode)->toBe(ErrorCode::NotFound);
});

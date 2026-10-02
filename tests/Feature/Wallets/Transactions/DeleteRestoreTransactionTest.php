<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionVersionConflict;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Delete and restore (spec 012, US3 S5-S6; FR-012, FR-014, R-2; P-07 rule 5,
 * ADR-0008 rule 14): a tombstone, never a removal; an explicit undelete.
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    $this->id = (string) Str::uuid7();
    $this->service = app(TransactionService::class);
    saveTransaction($this->owner->id, $this->wallet->id, $this->id, transactionBody(['amount_minor' => 2500]));
});

test('a delete tombstones, removes the postings and returns the balance', function () {
    $view = $this->service->delete($this->owner->id, $this->wallet->id, $this->id, 1, presentTransaction());

    expect($view->transaction->isTombstone())->toBeTrue()
        ->and($view->transaction->version)->toBe(2)
        ->and(Transaction::query()->count())->toBe(1)
        ->and(Posting::query()->count())->toBe(0)
        ->and(walletBalance($this->wallet->id))->toBe(50000);
    expectLedgerIntact();
    expectTransactionScopeIntact($this->wallet->id);
});

test('deleting a tombstone returns it unchanged, whatever version is sent', function () {
    $first = $this->service->delete($this->owner->id, $this->wallet->id, $this->id, 1, presentTransaction());

    $again = $this->service->delete($this->owner->id, $this->wallet->id, $this->id, 99, presentTransaction());

    expect($again->transaction->version)->toBe($first->transaction->version)
        ->and($again->transaction->changeSeq)->toBe($first->transaction->changeSeq)
        ->and($again->transaction->deletedAt)->toEqual($first->transaction->deletedAt);
});

test('a stale delete of a live transaction is a conflict', function () {
    expect(refusalOf(fn () => $this->service->delete($this->owner->id, $this->wallet->id, $this->id, 7, presentTransaction())))->toBeInstanceOf(TransactionVersionConflict::class)
        ->and(Transaction::query()->find($this->id)->deleted_at)->toBeNull();
});

test('an edit of a tombstone is a conflict carrying the tombstone, never an implicit restore', function () {
    $this->service->delete($this->owner->id, $this->wallet->id, $this->id, 1, presentTransaction());

    $conflict = refusalOf(fn () => $this->service->patch($this->owner->id, $this->wallet->id, $this->id, ['amount_minor' => 5], 2, presentTransaction()));

    expect($conflict)->toBeInstanceOf(TransactionVersionConflict::class)
        ->and($conflict->extensions['current']['deleted_at'])->not->toBeNull()
        ->and(Transaction::query()->find($this->id)->deleted_at)->not->toBeNull();
});

test('a restore makes it live at version + 1, re-derives the postings and moves the balance again', function () {
    $this->service->delete($this->owner->id, $this->wallet->id, $this->id, 1, presentTransaction());

    $view = $this->service->restore($this->owner->id, $this->wallet->id, $this->id, 2, presentTransaction());

    expect($view->transaction->isTombstone())->toBeFalse()
        ->and($view->transaction->version)->toBe(3)
        ->and(Posting::query()->where('transaction_id', $this->id)->count())->toBe(2)
        ->and(walletBalance($this->wallet->id))->toBe(47500);
    expectLedgerIntact();
    expectTransactionScopeIntact($this->wallet->id);
});

test('a stale restore is a conflict and a restore of a live transaction changes nothing', function () {
    expect($this->service->restore($this->owner->id, $this->wallet->id, $this->id, 1, presentTransaction())->transaction->version)->toBe(1);

    $this->service->delete($this->owner->id, $this->wallet->id, $this->id, 1, presentTransaction());

    expect(refusalOf(fn () => $this->service->restore($this->owner->id, $this->wallet->id, $this->id, 1, presentTransaction())))->toBeInstanceOf(TransactionVersionConflict::class)
        ->and(Transaction::query()->find($this->id)->deleted_at)->not->toBeNull();
});

test('a restore re-checks the rules against the stored record', function () {
    $stranger = person('grace@example.test');
    $partner = ownedWallet($stranger->id);
    addMember($partner->id, $this->owner->id, 'editor');
    $transfer = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->wallet->id, $transfer, transferBody($partner->id));
    $this->service->delete($this->owner->id, $this->wallet->id, $transfer, 1, presentTransaction());
    DB::table('wallet_members')->where('wallet_id', $partner->id)->where('user_id', $this->owner->id)->delete();

    expect(fieldErrorsOf(refusalOf(fn () => $this->service->restore($this->owner->id, $this->wallet->id, $transfer, 2, presentTransaction()))))->toBe(['/counter_wallet_id INVALID_ENUM_VALUE']);
});

test('created_by never changes through edits, deletes and restores', function () {
    $editor = person('grace@example.test');
    addMember($this->wallet->id, $editor->id, 'editor');

    $this->service->patch($editor->id, $this->wallet->id, $this->id, ['amount_minor' => 1], 1, presentTransaction());
    $this->service->delete($editor->id, $this->wallet->id, $this->id, 2, presentTransaction());
    $this->service->restore($editor->id, $this->wallet->id, $this->id, 3, presentTransaction());

    expect(Transaction::query()->find($this->id)->created_by)->toBe($this->owner->id);
});

test('delete and restore of an unknown id are not found', function () {
    $unknown = (string) Str::uuid7();

    expect(refusalOf(fn () => $this->service->delete($this->owner->id, $this->wallet->id, $unknown, 1, presentTransaction()))?->errorCode)->toBe(ErrorCode::NotFound)
        ->and(refusalOf(fn () => $this->service->restore($this->owner->id, $this->wallet->id, $unknown, 1, presentTransaction()))?->errorCode)->toBe(ErrorCode::NotFound);
});

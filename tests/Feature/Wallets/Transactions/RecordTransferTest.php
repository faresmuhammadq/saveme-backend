<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Moving money between two of my wallets (spec 012, US2 S1, S3-S6; FR-007,
 * FR-008, R-1, R-5, R-6; ADR-0015): one record, both balances, both feeds.
 */

beforeEach(function () {
    $this->owner = person();
    $this->source = ownedWallet($this->owner->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    $this->destination = ownedWallet($this->owner->id, ['opening_balance_minor' => 1000, 'balance_minor' => 1000]);
    $this->id = (string) Str::uuid7();
    $this->service = app(TransactionService::class);
});

test('one row, two wallet postings, both balances recomputed', function () {
    $view = saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($this->destination->id, ['amount_minor' => 3000]));

    expect(Transaction::query()->count())->toBe(1)
        ->and($view->transaction->walletId)->toBe($this->source->id)
        ->and($view->transaction->counterWalletId)->toBe($this->destination->id)
        ->and($view->transaction->categoryId)->toBeNull()
        ->and(walletBalance($this->source->id))->toBe(47000)
        ->and(walletBalance($this->destination->id))->toBe(4000);
    expectLedgerIntact();
});

test('change_seq comes from the source and counter_change_seq from the destination, each just below its wallet', function () {
    saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($this->destination->id));

    $row = Transaction::query()->find($this->id);
    expect($row->change_seq)->toBe(Wallet::query()->find($this->source->id)->change_seq - 1)
        ->and($row->counter_change_seq)->toBe(Wallet::query()->find($this->destination->id)->change_seq - 1);
    expectTransactionScopeIntact($this->source->id);
    expectTransactionScopeIntact($this->destination->id);
});

test('a cross-currency transfer is a currency mismatch at the destination', function () {
    $usd = ownedWallet($this->owner->id, ['currency' => 'USD']);

    expect(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($usd->id)))))
        ->toBe(['/counter_wallet_id CURRENCY_MISMATCH']);
    expect(Transaction::query()->count())->toBe(0);
});

test('a viewer of the destination is forbidden', function () {
    $partner = person('grace@example.test');
    $theirs = ownedWallet($partner->id);
    addMember($theirs->id, $this->owner->id, 'viewer');

    expect(refusalOf(fn () => saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($theirs->id)))?->errorCode)->toBe(ErrorCode::Forbidden);
    expect(Transaction::query()->count())->toBe(0);
});

test('an editor of both wallets may transfer', function () {
    $partner = person('grace@example.test');
    $theirs = ownedWallet($partner->id);
    addMember($theirs->id, $this->owner->id, 'editor');

    expect(refusalOf(fn () => saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($theirs->id))))->toBeNull();
});

test('a destination that is not a membership, unknown, deleted or the source itself is one invalid enum value', function (string $case) {
    $stranger = person('grace@example.test');
    $destination = match ($case) {
        'not a membership' => ownedWallet($stranger->id)->id,
        'unknown' => (string) Str::uuid7(),
        'deleted' => ownedWallet($this->owner->id, ['deleted_at' => now()])->id,
        'the source' => $this->source->id,
    };

    expect(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($destination)))))
        ->toBe(['/counter_wallet_id INVALID_ENUM_VALUE']);
})->with(['not a membership', 'unknown', 'deleted', 'the source']);

test('a category on a transfer is refused', function () {
    expect(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($this->destination->id, ['category_id' => platformCategoryId()])))))
        ->toBe(['/category_id INVALID_ENUM_VALUE']);
});

test('an archived destination is accepted', function () {
    $archived = ownedWallet($this->owner->id, ['archived_at' => now()]);

    expect(refusalOf(fn () => saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($archived->id))))->toBeNull();
});

test('a delete removes both effects', function () {
    saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($this->destination->id, ['amount_minor' => 3000]));

    $this->service->delete($this->owner->id, $this->source->id, $this->id, 1, presentTransaction());

    expect(walletBalance($this->source->id))->toBe(50000)->and(walletBalance($this->destination->id))->toBe(1000);
    expectLedgerIntact();
});

test('the destination can never change, and an edit works under either path', function () {
    $third = ownedWallet($this->owner->id);
    saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($this->destination->id, ['amount_minor' => 3000]));

    expect(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($third->id, ['amount_minor' => 3000], version: 1)))))
        ->toBe(['/counter_wallet_id IMMUTABLE_FIELD']);

    $edited = $this->service->patch($this->owner->id, $this->destination->id, $this->id, ['amount_minor' => 500], 1, presentTransaction());

    expect($edited->transaction->version)->toBe(2)
        ->and(walletBalance($this->source->id))->toBe(49500)
        ->and(walletBalance($this->destination->id))->toBe(1500);
    expectLedgerIntact();
});

test('a person who is a viewer of the other wallet cannot edit or delete the transfer', function () {
    $partner = person('grace@example.test');
    $theirs = ownedWallet($partner->id);
    addMember($theirs->id, $this->owner->id, 'editor');
    saveTransaction($this->owner->id, $this->source->id, $this->id, transferBody($theirs->id));
    DB::table('wallet_members')->where('wallet_id', $theirs->id)->where('user_id', $this->owner->id)->update(['role' => 'viewer']);

    expect(refusalOf(fn () => $this->service->patch($this->owner->id, $this->source->id, $this->id, ['amount_minor' => 5], 1, presentTransaction()))?->errorCode)->toBe(ErrorCode::Forbidden)
        ->and(refusalOf(fn () => $this->service->delete($this->owner->id, $this->source->id, $this->id, 1, presentTransaction()))?->errorCode)->toBe(ErrorCode::Forbidden);
});

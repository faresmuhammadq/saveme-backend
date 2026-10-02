<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Str;

/*
 * A deleted wallet's transactions (spec 012, FR-015, FR-027, R-9, A-4; P-08
 * rule 2): its own go with it, transfers to a live wallet stay live and are
 * neither edited nor restored, and no other wallet is written.
 */

beforeEach(function () {
    $this->owner = person();
    $this->partner = person('grace@example.test');
    $this->doomed = ownedWallet($this->owner->id, ['opening_balance_minor' => 10000, 'balance_minor' => 10000]);
    $this->kept = ownedWallet($this->owner->id, ['opening_balance_minor' => 5000, 'balance_minor' => 5000]);
    $this->third = ownedWallet($this->owner->id);
    $this->service = app(TransactionService::class);
});

function deleteWalletNow(string $userId, string $walletId): void
{
    app(WalletService::class)->delete($userId, $walletId, (int) Wallet::query()->find($walletId)->version, presentWallet());
}

test('income and expense go with the wallet, postings removed, change_seq stamped', function () {
    $own = recordTransaction($this->owner->id, $this->doomed->id, transactionBody())->transaction;
    $before = Transaction::query()->find($own->id);

    deleteWalletNow($this->owner->id, $this->doomed->id);

    $after = Transaction::query()->find($own->id);
    expect($after->deleted_at)->not->toBeNull()
        ->and($after->version)->toBe($before->version + 1)
        ->and($after->change_seq)->toBeGreaterThan($before->change_seq)
        ->and(Posting::query()->where('transaction_id', $own->id)->count())->toBe(0);
});

test('a transfer to a live wallet stays live with its postings and the live balance is unchanged', function () {
    $transfer = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->doomed->id, $transfer, transferBody($this->kept->id, ['amount_minor' => 2000]));
    $keptBalance = walletBalance($this->kept->id);

    deleteWalletNow($this->owner->id, $this->doomed->id);

    $row = Transaction::query()->find($transfer);
    expect($row->deleted_at)->toBeNull()
        ->and(Posting::query()->where('transaction_id', $transfer)->count())->toBe(2)
        ->and(walletBalance($this->kept->id))->toBe($keptBalance)->toBe(7000);
});

test('a transfer between two wallets both deleted goes with the second deletion', function () {
    $transfer = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->doomed->id, $transfer, transferBody($this->kept->id));

    deleteWalletNow($this->owner->id, $this->doomed->id);
    deleteWalletNow($this->owner->id, $this->kept->id);

    $row = Transaction::query()->find($transfer);
    expect($row->deleted_at)->not->toBeNull()->and(Posting::query()->where('transaction_id', $transfer)->count())->toBe(0);
});

test('such a transfer can be deleted through the live wallet, never edited or restored', function () {
    $transfer = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->doomed->id, $transfer, transferBody($this->kept->id, ['amount_minor' => 2000]));
    deleteWalletNow($this->owner->id, $this->doomed->id);

    $edit = refusalOf(fn () => $this->service->patch($this->owner->id, $this->kept->id, $transfer, ['amount_minor' => 1], 1, presentTransaction()));
    expect(fieldErrorsOf($edit))->toBe(['/counter_wallet_id INVALID_ENUM_VALUE']);

    $this->service->delete($this->owner->id, $this->kept->id, $transfer, 1, presentTransaction());
    expect(Transaction::query()->find($transfer)->deleted_at)->not->toBeNull()
        ->and(walletBalance($this->kept->id))->toBe(5000)
        ->and(Posting::query()->where('transaction_id', $transfer)->count())->toBe(0);

    $restore = refusalOf(fn () => $this->service->restore($this->owner->id, $this->kept->id, $transfer, 2, presentTransaction()));
    expect(fieldErrorsOf($restore))->toBe(['/counter_wallet_id INVALID_ENUM_VALUE']);
});

test('the deleted wallet is not found for writes, and other wallets are not locked or stamped', function () {
    $transfer = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->doomed->id, $transfer, transferBody($this->kept->id));
    $keptSeq = Wallet::query()->find($this->kept->id)->change_seq;

    deleteWalletNow($this->owner->id, $this->doomed->id);

    expect(Wallet::query()->find($this->kept->id)->change_seq)->toBe($keptSeq)
        ->and(refusalOf(fn () => saveTransaction($this->owner->id, $this->doomed->id, (string) Str::uuid7(), transactionBody()))?->errorCode)->toBe(ErrorCode::NotFound);
});

test('a transaction id written to a wallet or a category path is not found', function () {
    $tid = recordTransaction($this->owner->id, $this->kept->id)->transaction->id;
    $category = ownedCategory($this->owner->id);

    expect(refusalOf(fn () => app(WalletService::class)->save($this->owner->id, $tid, walletValues(), 0, presentWallet()))?->errorCode)->toBe(ErrorCode::NotFound)
        ->and(refusalOf(fn () => app(CategoryService::class)->save($this->owner->id, $tid, categoryValues(), 0, presentCategory()))?->errorCode)->toBe(ErrorCode::NotFound)
        ->and(Wallet::query()->whereKey($tid)->exists())->toBeFalse();
    expect($category->id)->not->toBe($tid);
});

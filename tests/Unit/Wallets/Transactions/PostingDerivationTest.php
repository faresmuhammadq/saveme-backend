<?php

use App\Modules\Shared\Domain\Money\Money;
use App\Modules\Wallets\Domain\Transactions\PostingDerivation;
use App\Modules\Wallets\Domain\Transactions\PostingLine;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Domain\Transactions\TransactionRecord;

/*
 * Posting derivation (spec 012, R-2; ADR-0012, P-04): two postings per
 * transaction, summing to zero, each against exactly one wallet or category.
 */

function recordOf(TransactionKind $kind, int $amount = 2500, string $currency = 'EGP'): TransactionRecord
{
    $now = new DateTimeImmutable;

    return new TransactionRecord(
        id: 'tx', walletId: 'wallet-a', kind: $kind, date: '2026-10-01', amountMinor: $amount, currency: $currency,
        categoryId: $kind === TransactionKind::Transfer || $kind->isAdjustment() ? null : 'category',
        counterWalletId: $kind === TransactionKind::Transfer ? 'wallet-b' : null,
        description: null, memo: null, counterparty: null, cleared: false, createdBy: 'user',
        version: 1, changeSeq: 1, counterChangeSeq: null, createdAt: $now, updatedAt: $now, deletedAt: null,
    );
}

test('an expense takes money out of the wallet into the category', function () {
    [$wallet, $category] = PostingDerivation::for(recordOf(TransactionKind::Expense));

    expect($wallet->walletId)->toBe('wallet-a')->and($wallet->money->amountMinor)->toBe(-2500)
        ->and($category->categoryId)->toBe('category')->and($category->money->amountMinor)->toBe(2500);
});

test('an income brings money from the category into the wallet', function () {
    [$category, $wallet] = PostingDerivation::for(recordOf(TransactionKind::Income));

    expect($category->categoryId)->toBe('category')->and($category->money->amountMinor)->toBe(-2500)
        ->and($wallet->walletId)->toBe('wallet-a')->and($wallet->money->amountMinor)->toBe(2500);
});

test('a transfer takes money out of the source and into the destination, both wallets', function () {
    [$source, $destination] = PostingDerivation::for(recordOf(TransactionKind::Transfer));

    expect($source->walletId)->toBe('wallet-a')->and($source->categoryId)->toBeNull()->and($source->money->amountMinor)->toBe(-2500)
        ->and($destination->walletId)->toBe('wallet-b')->and($destination->categoryId)->toBeNull()->and($destination->money->amountMinor)->toBe(2500);
});

test('an adjustment in raises the wallet from the internal category, an adjustment out lowers it into it', function () {
    [$category, $wallet] = PostingDerivation::for(recordOf(TransactionKind::AdjustmentIn));
    [$out, $category2] = PostingDerivation::for(recordOf(TransactionKind::AdjustmentOut));

    expect($category->categoryId)->toBe(PostingDerivation::ADJUSTMENT_CATEGORY_ID)->and($category->money->amountMinor)->toBe(-2500)
        ->and($wallet->walletId)->toBe('wallet-a')->and($wallet->money->amountMinor)->toBe(2500)
        ->and($out->walletId)->toBe('wallet-a')->and($out->money->amountMinor)->toBe(-2500)
        ->and($category2->categoryId)->toBe(PostingDerivation::ADJUSTMENT_CATEGORY_ID)->and($category2->money->amountMinor)->toBe(2500);
});

test('every kind yields two postings that sum to zero with exactly one target each', function (TransactionKind $kind) {
    $lines = PostingDerivation::for(recordOf($kind, 9007199254740991, 'USD'));
    $sum = Money::of(0, 'USD');

    foreach ($lines as $line) {
        expect(($line->walletId === null) !== ($line->categoryId === null))->toBeTrue();
        $sum = $sum->plus($line->money);
    }

    expect($lines)->toHaveCount(2)->and($sum->isZero())->toBeTrue();
})->with([TransactionKind::Expense, TransactionKind::Income, TransactionKind::Transfer, TransactionKind::AdjustmentIn, TransactionKind::AdjustmentOut]);

test('a posting refuses both targets, neither, and a zero amount', function () {
    expect(fn () => new PostingLine('w', 'c', Money::of(1, 'EGP')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PostingLine(null, null, Money::of(1, 'EGP')))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PostingLine::forWallet('w', Money::of(0, 'EGP')))->toThrow(InvalidArgumentException::class);
});

test('a transaction kind has exactly income, expense, transfer and the two adjustments', function () {
    expect(array_map(fn (TransactionKind $kind): string => $kind->value, TransactionKind::cases()))->toBe(['income', 'expense', 'transfer', 'adjustment_in', 'adjustment_out']);
});

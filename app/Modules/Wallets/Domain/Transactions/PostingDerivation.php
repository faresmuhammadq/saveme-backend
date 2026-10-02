<?php

namespace App\Modules\Wallets\Domain\Transactions;

use App\Modules\Shared\Domain\Money\Money;
use LogicException;

/**
 * Derives a transaction's two postings (ADR-0012, P-04). The person never
 * types a sign: the source side is negative, the destination positive.
 *
 * - expense: wallet -a, category +a
 * - income:  category -a, wallet +a
 * - transfer: source wallet -a, destination wallet +a
 */
final class PostingDerivation
{
    /**
     * @return list<PostingLine>
     */
    public static function for(TransactionRecord $transaction): array
    {
        $amount = Money::of($transaction->amountMinor, $transaction->currency);

        $lines = match ($transaction->kind) {
            TransactionKind::Expense => [
                PostingLine::forWallet($transaction->walletId, $amount->negate()),
                PostingLine::forCategory(self::category($transaction), $amount),
            ],
            TransactionKind::Income => [
                PostingLine::forCategory(self::category($transaction), $amount->negate()),
                PostingLine::forWallet($transaction->walletId, $amount),
            ],
            TransactionKind::Transfer => [
                PostingLine::forWallet($transaction->walletId, $amount->negate()),
                PostingLine::forWallet($transaction->counterWalletId ?? throw new LogicException('A transfer has a destination wallet.'), $amount),
            ],
        };

        $sum = Money::of(0, $transaction->currency);
        foreach ($lines as $line) {
            $sum = $sum->plus($line->money);
        }

        if (! $sum->isZero()) {
            throw new LogicException('The postings of a transaction must sum to zero.');
        }

        return $lines;
    }

    private static function category(TransactionRecord $transaction): string
    {
        return $transaction->categoryId ?? throw new LogicException('An income or expense has a category.');
    }
}

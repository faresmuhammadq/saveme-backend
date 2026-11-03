<?php

namespace App\Modules\Wallets\Domain\Transactions;

/**
 * What a transaction is. `income` and `expense` move money between a wallet
 * and a category; `transfer` moves it between two wallets and is excluded
 * from every spending and income total; `adjustment_in` and `adjustment_out`
 * correct a wallet's balance and are excluded the same way (ADR-0028). Never
 * changes after creation.
 */
enum TransactionKind: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Transfer = 'transfer';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';

    /**
     * A balance adjustment (ADR-0028): raises or lowers a wallet's balance
     * without being spending or income. It has no category and no counter
     * wallet on the wire.
     */
    public function isAdjustment(): bool
    {
        return $this === self::AdjustmentIn || $this === self::AdjustmentOut;
    }
}

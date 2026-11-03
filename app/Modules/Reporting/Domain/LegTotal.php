<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * The sum of one wallet's legs of one kind in a range, signed as the ledger
 * has them: income positive, expense negative, a transfer negative on the
 * source and positive on the destination. `otherWalletId` is the wallet on
 * the other side of a transfer. A balance adjustment is its own kind and
 * is neither income nor expense (ADR-0028).
 */
final readonly class LegTotal
{
    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const TRANSFER = 'transfer';

    public const ADJUSTMENT_IN = 'adjustment_in';

    public const ADJUSTMENT_OUT = 'adjustment_out';

    public function __construct(
        public string $walletId,
        public string $kind,
        public ?string $otherWalletId,
        public Money $amount,
    ) {}
}

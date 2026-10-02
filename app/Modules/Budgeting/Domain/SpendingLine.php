<?php

namespace App\Modules\Budgeting\Domain;

/**
 * The owner's spending in one category and currency over a Budget Period,
 * as Wallets & Ledger reports it.
 */
final readonly class SpendingLine
{
    public function __construct(
        public string $categoryId,
        public string $currency,
        public int $amountMinor,
    ) {}
}

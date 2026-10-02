<?php

namespace App\Modules\Wallets\Application\Transactions\Dto;

/**
 * One person's spending in one category and currency over a span of dates
 * (spec 015, plan design note 3).
 */
final readonly class SpendingTotal
{
    public function __construct(
        public string $categoryId,
        public string $currency,
        public int $amountMinor,
    ) {}
}

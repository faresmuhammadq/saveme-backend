<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * The positive amount of scheduled occurrences of one shape on one day.
 * Occurrences have no postings, so the amount and the kind are the figure.
 */
final readonly class ScheduledTotal
{
    /**
     * @param  string  $date  YYYY-MM-DD
     */
    public function __construct(
        public string $date,
        public string $kind,
        public ?string $categoryId,
        public ?string $systemKey,
        public string $walletId,
        public ?string $counterWalletId,
        public Money $amount,
    ) {}
}

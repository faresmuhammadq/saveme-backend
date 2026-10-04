<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * The sum of one kind's wallet legs (income or expense) dated one day,
 * signed as the ledger has them: expense legs are negative.
 */
final readonly class DailyTotal
{
    /**
     * @param  string  $date  YYYY-MM-DD
     */
    public function __construct(
        public string $date,
        public string $kind,
        public Money $amount,
    ) {}
}

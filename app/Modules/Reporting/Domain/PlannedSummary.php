<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * Sums of scheduled occurrences in a range, kept apart from the actual
 * figures and never entering a balance.
 */
final readonly class PlannedSummary
{
    public function __construct(
        public Money $income,
        public Money $expenses,
        public Money $transfersIn,
        public Money $transfersOut,
    ) {}
}

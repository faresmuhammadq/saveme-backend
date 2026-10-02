<?php

namespace App\Modules\Wallets\Domain\Recurring;

/**
 * `per_occurrence`: every round is the rule's amount. `total`: the amount is
 * split over the rounds by `Money::allocate()`.
 */
enum AmountBasis: string
{
    case PerOccurrence = 'per_occurrence';
    case Total = 'total';
}

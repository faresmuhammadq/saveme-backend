<?php

namespace App\Modules\Admin\Domain\Exceptions;

use RuntimeException;

/**
 * An operator has used their account lookups for the window (spec 021,
 * FR-061). Carries the whole minutes to wait, rounded up.
 */
final class LookupBudgetSpent extends RuntimeException
{
    public function __construct(public readonly int $minutes)
    {
        parent::__construct("lookup_budget_spent: try again in {$minutes} minutes.");
    }
}

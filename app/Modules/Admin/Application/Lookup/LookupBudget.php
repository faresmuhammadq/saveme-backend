<?php

namespace App\Modules\Admin\Application\Lookup;

use App\Modules\Admin\Domain\Exceptions\LookupBudgetSpent;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many account lookups one operator may make: 30 per 10 minutes (spec
 * 021, R-9, security baseline route budgets). Bound to the operator, not to
 * the session, so signing in again does not refill it.
 */
final class LookupBudget
{
    public const ATTEMPTS = 30;

    public const WINDOW_SECONDS = 600;

    /**
     * @throws LookupBudgetSpent
     */
    public function assertAvailable(string $operatorId): void
    {
        if (RateLimiter::tooManyAttempts($this->key($operatorId), self::ATTEMPTS)) {
            throw new LookupBudgetSpent($this->minutesToWait($operatorId));
        }
    }

    /**
     * Spends one unit; refuses when none is left.
     *
     * @throws LookupBudgetSpent
     */
    public function spend(string $operatorId): void
    {
        $this->assertAvailable($operatorId);

        RateLimiter::hit($this->key($operatorId), self::WINDOW_SECONDS);
    }

    private function minutesToWait(string $operatorId): int
    {
        return max(1, (int) ceil(RateLimiter::availableIn($this->key($operatorId)) / 60));
    }

    private function key(string $operatorId): string
    {
        return 'admin-lookup:'.$operatorId;
    }
}

<?php

namespace App\Modules\Budgeting\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A budget's spending went strictly above its limit in a Budget Period, for
 * the first time (spec 023, FR-026, ADR-0025). Published from the insert of
 * the Budget Overrun that records it, so once per budget, period and
 * threshold. Dispatched after commit, identifiers and scalars only (P-11):
 * no amount.
 */
final readonly class BudgetExceeded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $budgetId,
        public string $userId,
        public string $periodStart,
        public int $threshold,
    ) {}
}

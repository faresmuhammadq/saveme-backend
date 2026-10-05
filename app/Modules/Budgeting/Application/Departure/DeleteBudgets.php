<?php

namespace App\Modules\Budgeting\Application\Departure;

use App\Modules\Budgeting\Application\BudgetService;
use App\Modules\Identity\Application\Deletion\AccountPurgeParticipant;
use App\Modules\Identity\Application\Deletion\PurgeContext;
use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;

/**
 * Budgeting's step of an account purge (spec 022, FR-046): every budget of
 * the departing person is tombstoned and then removed. A no-op once none is
 * left.
 */
final class DeleteBudgets implements AccountPurgeParticipant
{
    public function __construct(private readonly BudgetService $budgets) {}

    public function step(): PurgeStep
    {
        return PurgeStep::Budgets;
    }

    public function purge(string $userId, PurgeContext $context): PurgeCounts
    {
        return PurgeCounts::of('budgets_deleted', $this->budgets->removeAllForDepartedUser($userId));
    }
}

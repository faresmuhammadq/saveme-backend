<?php

namespace App\Modules\Budgeting\Application\Departure;

use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
use App\Modules\Identity\Application\Deletion\AccountPurgeParticipant;
use App\Modules\Identity\Application\Deletion\PurgeContext;
use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;

/**
 * Budgeting's Shared Budget step of an account purge (ADR-0027 rule 15): each
 * owned budget passes to its earliest member, or is deleted when nobody else
 * is in it, and the person leaves every other one. Their entries keep amount,
 * date and category and lose their notes. A no-op once no membership is left.
 */
final class LeaveSharedBudgets implements AccountPurgeParticipant
{
    public function __construct(private readonly SharedBudgetService $budgets) {}

    public function step(): PurgeStep
    {
        return PurgeStep::SharedBudgets;
    }

    public function purge(string $userId, PurgeContext $context): PurgeCounts
    {
        return PurgeCounts::of('shared_budgets_left', $this->budgets->removeDepartedUser($userId));
    }
}

<?php

namespace App\Modules\Budgeting\Application\SharedBudgets;

use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetActionForbidden;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;

/**
 * Who may act on a Shared Budget (ADR-0027 rules 2 and 3): a non-member gets
 * not-found and a member without the role gets forbidden. Membership of a
 * budget never grants access to a wallet.
 */
final class SharedBudgetAuthorizer
{
    public function __construct(private readonly SharedBudgetRepository $budgets) {}

    /**
     * @throws SharedBudgetNotFound
     */
    public function member(string $actorId, string $budgetId): SharedBudgetMemberRecord
    {
        return $this->budgets->activeMember($budgetId, $actorId) ?? throw new SharedBudgetNotFound;
    }

    /**
     * @throws SharedBudgetNotFound
     * @throws SharedBudgetActionForbidden
     */
    public function owner(string $actorId, string $budgetId): SharedBudgetMemberRecord
    {
        $member = $this->member($actorId, $budgetId);

        return $member->isOwner() ? $member : throw new SharedBudgetActionForbidden;
    }
}

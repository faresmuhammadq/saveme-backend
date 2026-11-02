<?php

namespace App\Modules\Budgeting\Application\SharedBudgets;

use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;

/**
 * What another module may ask of a Shared Budget without being a member of it:
 * Sharing's invitations need the owner, the seat count and a preview.
 */
final class SharedBudgetDirectory
{
    public function __construct(private readonly SharedBudgetRepository $budgets) {}

    public function find(string $budgetId): ?SharedBudgetRecord
    {
        return $this->budgets->find($budgetId);
    }

    public function activeMemberCount(string $budgetId): int
    {
        return count($this->budgets->activeMembers($budgetId));
    }

    public function memberOf(string $budgetId, string $userId): ?SharedBudgetMemberRecord
    {
        return $this->budgets->activeMember($budgetId, $userId);
    }

    /**
     * @return list<string>
     */
    public function ownedLiveBudgetIds(string $userId): array
    {
        return $this->budgets->ownedLiveBudgetIds($userId);
    }

    public function isActiveMember(string $budgetId, string $userId): bool
    {
        return $this->budgets->activeMember($budgetId, $userId) !== null;
    }

    public function isOwner(string $budgetId, string $userId): bool
    {
        return $this->budgets->activeMember($budgetId, $userId)?->isOwner() === true;
    }

    /**
     * Whether the identifier already names a budget, a line or a person's Budget (P-06 rule 3).
     */
    public function identifierTaken(string $id): bool
    {
        return $this->budgets->identifierTaken($id);
    }
}

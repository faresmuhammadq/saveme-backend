<?php

namespace App\Modules\Sharing\Application\SharedBudgets;

use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetDirectory;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetActionForbidden;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Sharing\Domain\Refusals\InvitationNotFound;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRecord;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRepository;

/**
 * Reads of a Shared Budget's invitations: the owner's, and nobody else's. A
 * non-member gets not-found and a member who is not the owner gets forbidden.
 */
final class SharedBudgetInvitationQueryService
{
    public function __construct(
        private readonly SharedBudgetInvitationRepository $invitations,
        private readonly SharedBudgetDirectory $directory,
    ) {}

    /**
     * The budget's pending invitations, newest first.
     *
     * @return list<SharedBudgetInvitationRecord>
     *
     * @throws SharedBudgetNotFound
     * @throws SharedBudgetActionForbidden
     */
    public function pending(string $userId, string $budgetId): array
    {
        $this->assertOwner($userId, $budgetId);

        return $this->invitations->pendingIn($budgetId);
    }

    /**
     * @throws SharedBudgetNotFound
     * @throws SharedBudgetActionForbidden
     * @throws InvitationNotFound
     */
    public function find(string $userId, string $budgetId, string $invitationId): SharedBudgetInvitationRecord
    {
        $this->assertOwner($userId, $budgetId);

        $invitation = $this->invitations->find($invitationId);

        return $invitation !== null && $invitation->sharedBudgetId === $budgetId ? $invitation : throw new InvitationNotFound;
    }

    private function assertOwner(string $userId, string $budgetId): void
    {
        if (! $this->directory->isActiveMember($budgetId, $userId)) {
            throw new SharedBudgetNotFound;
        }

        if (! $this->directory->isOwner($budgetId, $userId)) {
            throw new SharedBudgetActionForbidden;
        }
    }
}

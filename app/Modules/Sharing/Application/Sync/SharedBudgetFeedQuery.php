<?php

namespace App\Modules\Sharing\Application\Sync;

use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetDirectory;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRecord;
use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRepository;

/**
 * Sharing's side of GET /sync/shared-budgets/{id}/changes (ADR-0027): the
 * budget's invitations above a position, pending live and closed as
 * tombstones, rendered as the invitation reads render them. Positions come
 * from the budget's counter. Only the owner sees them: for any other member
 * the source is empty. The Sync feed has already authorised the reader as a
 * member through Budgeting's scope check.
 */
final class SharedBudgetFeedQuery
{
    public function __construct(
        private readonly SharedBudgetInvitationRepository $invitations,
        private readonly SharedBudgetDirectory $directory,
        private readonly FeedPresenter $presenter,
    ) {}

    /**
     * Rows above $after, in position order, at most $limit + 1.
     *
     * @return list<FeedRecord>
     */
    public function page(string $readerId, string $budgetId, int $after, int $limit): array
    {
        if (! $this->directory->isOwner($budgetId, $readerId)) {
            return [];
        }

        return array_map(
            fn (SharedBudgetInvitationRecord $invitation): FeedRecord => new FeedRecord('shared_budget_invitation', $invitation->changeSeq, $this->presenter->sharedBudgetInvitation($invitation)),
            $this->invitations->changedIn($budgetId, $after, $limit + 1),
        );
    }
}

<?php

namespace App\Modules\Budgeting\Application\Sync;

use App\Modules\Budgeting\Domain\Ports\BudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Identity\Application\Profiles\DisplayNames;

/**
 * Budgeting's side of GET /sync/user/changes (spec 015, FR-019): the person's
 * own budgets above a position, tombstones included, rendered as
 * GET /budgets/{id} renders them. Positions come from the owner's user
 * counter. A budget is never on a wallet feed.
 */
final class UserFeedQuery
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly FeedPresenter $presenter,
        private readonly SharedBudgetRepository $sharedBudgets,
        private readonly SharedBudgetFeedPresenter $sharedPresenter,
        private readonly DisplayNames $displayNames,
    ) {}

    /**
     * Rows above $after, in position order, at most $limit + 1.
     *
     * @return list<FeedRecord>
     */
    public function page(string $userId, int $after, int $limit): array
    {
        $memberships = $this->sharedBudgets->membershipsChangedAfter($userId, $after, $limit + 1);
        $names = $this->displayNames->of([$userId]);

        $rows = [
            ...array_map(
                fn ($budget): FeedRecord => new FeedRecord('budget', $budget->changeSeq, $this->presenter->budget($budget)),
                $this->budgets->changedFor($userId, $after, $limit + 1),
            ),
            // The person's own memberships only (ADR-0017 rule 3); a tombstoned one means they no longer belong.
            ...array_map(
                fn ($member): FeedRecord => new FeedRecord('shared_budget_member', $member->memberChangeSeq, $this->sharedPresenter->member($member, $names[$userId] ?? null)),
                $memberships,
            ),
        ];

        usort($rows, fn (FeedRecord $a, FeedRecord $b): int => $a->position <=> $b->position);

        return array_slice($rows, 0, $limit + 1);
    }
}

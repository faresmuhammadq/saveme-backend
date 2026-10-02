<?php

namespace App\Modules\Budgeting\Application\Sync;

use App\Modules\Budgeting\Domain\Ports\BudgetRepository;

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
    ) {}

    /**
     * Rows above $after, in position order, at most $limit + 1.
     *
     * @return list<FeedRecord>
     */
    public function page(string $userId, int $after, int $limit): array
    {
        return array_map(
            fn ($budget): FeedRecord => new FeedRecord('budget', $budget->changeSeq, $this->presenter->budget($budget)),
            $this->budgets->changedFor($userId, $after, $limit + 1),
        );
    }
}

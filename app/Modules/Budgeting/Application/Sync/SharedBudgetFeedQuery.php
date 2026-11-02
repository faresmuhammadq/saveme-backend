<?php

namespace App\Modules\Budgeting\Application\Sync;

use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetAuthorizer;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetEntryRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetLineRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Identity\Application\Profiles\DisplayNames;

/**
 * The Shared Budget scope's side of GET /sync/shared-budgets/{id}/changes
 * (ADR-0027 rule 9): the budget, its members, lines and entries above a
 * position, tombstones included, rendered as their reads render them.
 * Positions come from the budget's own counter. A non-member is not-found
 * (P-08 rule 3); a person removed learns of it from their user feed.
 */
final class SharedBudgetFeedQuery
{
    public function __construct(
        private readonly SharedBudgetRepository $budgets,
        private readonly SharedBudgetAuthorizer $authorizer,
        private readonly DisplayNames $displayNames,
        private readonly SharedBudgetFeedPresenter $presenter,
    ) {}

    /**
     * The scope's counter and purge horizon, once the reader is a member.
     *
     * @return array{counter: int, purged: int}
     *
     * @throws SharedBudgetNotFound
     */
    public function scope(string $readerId, string $budgetId): array
    {
        $this->authorizer->member($readerId, $budgetId);
        $budget = $this->budgets->find($budgetId) ?? throw new SharedBudgetNotFound;

        return ['counter' => $budget->changeSeq, 'purged' => $budget->purgedThrough];
    }

    /**
     * Rows above $after, in position order, at most $limit + 1.
     *
     * @return list<FeedRecord>
     *
     * @throws SharedBudgetNotFound
     */
    public function page(string $readerId, string $budgetId, int $after, int $limit): array
    {
        $this->authorizer->member($readerId, $budgetId);
        $budget = $this->budgets->find($budgetId) ?? throw new SharedBudgetNotFound;

        $members = $this->budgets->membersChangedAfter($budgetId, $after, $limit + 1);
        $names = $this->displayNames->of(array_map(fn (SharedBudgetMemberRecord $member): string => $member->userId, $members));

        $rows = [
            ...array_map(fn (SharedBudgetMemberRecord $member): FeedRecord => new FeedRecord('shared_budget_member', $member->changeSeq, $this->presenter->member($member, $names[$member->userId] ?? null)), $members),
            ...array_map(fn (SharedBudgetLineRecord $line): FeedRecord => new FeedRecord('shared_budget_line', $line->changeSeq, $this->presenter->line($line)), $this->budgets->linesChangedAfter($budgetId, $after, $limit + 1)),
            ...array_map(fn (SharedBudgetEntryRecord $entry): FeedRecord => new FeedRecord('shared_budget_entry', $entry->changeSeq, $this->presenter->entry($entry)), $this->budgets->entriesChangedAfter($budgetId, $after, $limit + 1)),
        ];

        if ($budget->changeSeq > $after) {
            $rows[] = new FeedRecord('shared_budget', $budget->changeSeq, $this->presenter->budget($budget));
        }

        usort($rows, fn (FeedRecord $a, FeedRecord $b): int => $a->position <=> $b->position);

        return array_slice($rows, 0, $limit + 1);
    }
}

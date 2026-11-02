<?php

namespace App\Modules\Budgeting\Application\Sync;

use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;

/**
 * GET /sync/shared-budgets/heads: one entry per live Shared Budget the reader
 * actively belongs to. Membership is the whole authorization; a budget the
 * reader cannot read is absent, never refused (P-08 rule 3).
 */
final class SharedBudgetHeadsQuery
{
    public function __construct(private readonly SharedBudgetRepository $budgets) {}

    /**
     * @return list<array{shared_budget_id: string, change_seq: int, purged_change_seq: int}>
     */
    public function page(string $readerId, ?string $afterId, int $limit): array
    {
        return $this->budgets->headsFor($readerId, $afterId, $limit);
    }
}

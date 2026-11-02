<?php

namespace App\Modules\Budgeting\Application\Sync;

use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Identity\Application\Sync\TombstonePurge as UserScopeHorizon;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Shared Budget scope's side of the daily tombstone purge (ADR-0008 rule
 * 12, ADR-0027 rule 9): removes member, line and entry tombstones deleted
 * before the cut, raising each budget's purge horizon first, and a member's
 * user-scope horizon for the membership rows that ride their feed. Then a
 * deleted budget with nothing left to read goes whole. Idempotent and
 * restartable.
 *
 * LOCK ORDER (ADR-0027 rule 10): the budget row, then user rows ascending.
 */
final class SharedBudgetTombstonePurge
{
    public function __construct(
        private readonly SharedBudgetRepository $budgets,
        private readonly UserScopeHorizon $identity,
    ) {}

    /**
     * @return array<string, int> rows removed per record type
     */
    public function purge(DateTimeImmutable $before, int $batchSize = 500): array
    {
        $removed = ['shared_budget_members' => 0, 'shared_budget_lines' => 0, 'shared_budget_entries' => 0, 'shared_budgets' => 0];

        foreach (['members', 'lines', 'entries'] as $kind) {
            $afterId = '';

            while (($batch = $this->budgets->expiredTombstones($kind, $before, $afterId, $batchSize)) !== []) {
                $afterId = $batch[array_key_last($batch)]['id'];

                $removed['shared_budget_'.$kind] += DB::transaction(function () use ($batch, $kind, $before): int {
                    $horizons = [];
                    $userHorizons = [];

                    foreach ($batch as $row) {
                        $horizons[$row['shared_budget_id']] = max($horizons[$row['shared_budget_id']] ?? 0, $row['change_seq']);

                        if ($row['user_id'] !== null && $row['member_change_seq'] !== null) {
                            $userHorizons[$row['user_id']] = max($userHorizons[$row['user_id']] ?? 0, $row['member_change_seq']);
                        }
                    }

                    ksort($horizons, SORT_STRING);
                    ksort($userHorizons, SORT_STRING);

                    foreach ($horizons as $budgetId => $position) {
                        $this->budgets->findForUpdate((string) $budgetId);
                        $this->budgets->raiseHorizon((string) $budgetId, $position);
                    }

                    foreach ($userHorizons as $userId => $position) {
                        $this->identity->raiseHorizon((string) $userId, $position);
                    }

                    return $this->budgets->deleteTombstones($kind, array_column($batch, 'id'), $before);
                });
            }
        }

        $afterId = '';

        while (($ids = $this->budgets->expiredBudgets($before, $afterId, $batchSize)) !== []) {
            $afterId = $ids[array_key_last($ids)];

            foreach ($ids as $budgetId) {
                DB::transaction(function () use ($budgetId, &$removed): void {
                    $this->budgets->findForUpdate($budgetId);
                    $this->budgets->deleteBudget($budgetId);
                    $removed['shared_budgets']++;
                });
            }
        }

        return $removed;
    }
}

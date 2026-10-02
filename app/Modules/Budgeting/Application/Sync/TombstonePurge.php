<?php

namespace App\Modules\Budgeting\Application\Sync;

use App\Modules\Budgeting\Domain\Ports\BudgetRepository;
use App\Modules\Identity\Application\Sync\TombstonePurge as IdentityPurge;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Budgeting's side of the daily tombstone purge (spec 015, FR-021, spec 013
 * R-11): removes budget tombstones deleted before the cut in batches of 500
 * and raises each owner's purge horizon. Idempotent and restartable.
 *
 * LOCK ORDER (ADR-0017 rule 5): writers take the owner's counter before the
 * budget row, so the horizons are raised, owners ascending, before the rows
 * go. A tombstone is final, so every candidate is removed. Runs before
 * Wallets' purge, so a category freed by a purged budget can go in the same
 * run.
 */
final class TombstonePurge
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly IdentityPurge $identity,
    ) {}

    /**
     * @return array<string, int> rows removed per record type
     */
    public function purge(DateTimeImmutable $before, int $batchSize = 500): array
    {
        $removed = 0;
        $afterId = '';

        while (($batch = $this->budgets->nextExpiredTombstones($before, $afterId, $batchSize)) !== []) {
            $afterId = $batch[array_key_last($batch)]['id'];

            $removed += DB::transaction(function () use ($batch, $before): int {
                $horizons = [];
                foreach ($batch as $row) {
                    $horizons[$row['user_id']] = max($horizons[$row['user_id']] ?? 0, $row['change_seq']);
                }

                ksort($horizons, SORT_STRING);
                foreach ($horizons as $userId => $position) {
                    $this->identity->raiseHorizon((string) $userId, $position);
                }

                return count($this->budgets->deleteTombstones(array_column($batch, 'id'), $before));
            });
        }

        return ['budgets' => $removed];
    }
}

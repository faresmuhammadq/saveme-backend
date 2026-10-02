<?php

namespace App\Modules\Sharing\Application\Sync;

use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use App\Modules\Wallets\Application\Sync\PurgeHorizons;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sharing's side of the daily tombstone purge (spec 016, R-14; spec 013
 * R-11): removes invitation tombstones closed before the cut in batches of
 * 500 and raises each wallet's purge horizon, in the same transaction as the
 * removal and under the wallet lock a writer would take (wallets ascending).
 * Idempotent and restartable. Runs before Wallets' purge, so a deleted wallet
 * whose only remaining rows were invitation tombstones can go in the same run.
 */
final class TombstonePurge
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly PurgeHorizons $horizons,
    ) {}

    /**
     * @return array<string, int> rows removed per record type
     */
    public function purge(DateTimeImmutable $before, int $batchSize = 500): array
    {
        $removed = 0;
        $afterId = '';

        while (($batch = $this->invitations->nextExpiredTombstones($before, $afterId, $batchSize)) !== []) {
            $afterId = $batch[array_key_last($batch)]['id'];

            $removed += DB::transaction(function () use ($batch, $before): int {
                $walletIds = array_values(array_unique(array_column($batch, 'wallet_id')));
                sort($walletIds, SORT_STRING);
                $this->horizons->lockWallets($walletIds);

                $deleted = $this->invitations->deleteTombstones(array_column($batch, 'id'), $before);

                $positions = [];
                foreach ($deleted as $row) {
                    $positions[$row['wallet_id']] = max($positions[$row['wallet_id']] ?? 0, $row['change_seq']);
                }

                ksort($positions, SORT_STRING);
                foreach ($positions as $walletId => $position) {
                    $this->horizons->raise((string) $walletId, $position);
                }

                return count($deleted);
            });
        }

        return ['invitations' => $removed];
    }
}

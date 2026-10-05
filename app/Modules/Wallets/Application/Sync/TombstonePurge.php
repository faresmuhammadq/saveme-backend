<?php

namespace App\Modules\Wallets\Application\Sync;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Application\Sync\TombstonePurge as IdentityPurge;
use App\Modules\Wallets\Domain\Ports\TombstoneStore;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Wallets' side of the daily tombstone purge (spec 013, R-11, ADR-0008 rule
 * 12). Removes the syncable tombstones deleted before the cut, in batches
 * of 500 rows per transaction, and raises each affected scope's purge
 * horizon to the greatest position it removed, in the same transaction as
 * the removal and under the scope lock a writer would take. Idempotent and
 * restartable: a second run finds nothing, an interrupted one resumes.
 *
 * LOCK ORDER (ADR-0017 rule 5): category rows, then wallet rows ascending,
 * then user rows ascending. A transfer raises both its wallets' horizons
 * (source by `change_seq`, destination by `counter_change_seq`); a membership
 * raises its wallet's and its member's user scope's.
 *
 * Kept, not purged: a tombstoned rule while any occurrence, live or
 * tombstoned, exists, an occurrence while a transaction names it (spec 014,
 * FR-025), a tombstoned wallet any transaction, posting, reference,
 * membership, rule or occurrence still refers to (a live transfer can still post to a deleted
 * wallet, ADR-0015 rule 5), and a person's tombstoned category anything still
 * refers to. Both go on a later run once unreferenced.
 */
final class TombstonePurge
{
    public function __construct(
        private readonly TombstoneStore $store,
        private readonly IdentityPurge $identity,
        private readonly Accounts $accounts,
    ) {}

    /**
     * @return array<string, int> rows removed per record type
     */
    public function purge(DateTimeImmutable $before, int $batchSize = 500): array
    {
        return [
            'transactions' => $this->purgeTransactions($before, $batchSize),
            'occurrences' => $this->purgeOccurrences($before, $batchSize),
            'recurring_rules' => $this->purgeRules($before, $batchSize),
            'category_references' => $this->purgeReferences($before, $batchSize),
            'wallet_members' => $this->purgeMembers($before, $batchSize),
            'categories' => $this->purgeCategories($before, $batchSize),
            'departed_categories' => $this->purgeDepartedCategories($batchSize),
            'wallets' => $this->purgeWallets($before, $batchSize),
        ];
    }

    private function purgeTransactions(DateTimeImmutable $before, int $batchSize): int
    {
        $removed = 0;
        $afterId = '';

        while (($batch = $this->store->nextExpiredTransactions($before, $afterId, $batchSize)) !== []) {
            $afterId = $batch[array_key_last($batch)]['id'];

            $removed += DB::transaction(function () use ($batch, $before): int {
                $walletIds = [];
                foreach ($batch as $row) {
                    $walletIds[] = $row['wallet_id'];
                    if ($row['counter_wallet_id'] !== null) {
                        $walletIds[] = $row['counter_wallet_id'];
                    }
                }

                $this->store->lockWallets(self::ascending($walletIds));
                $deleted = $this->store->deleteTransactions(array_column($batch, 'id'), $before);

                $horizons = [];
                foreach ($deleted as $row) {
                    $horizons[$row['wallet_id']] = max($horizons[$row['wallet_id']] ?? 0, $row['change_seq']);
                    if ($row['counter_wallet_id'] !== null && $row['counter_change_seq'] !== null) {
                        $horizons[$row['counter_wallet_id']] = max($horizons[$row['counter_wallet_id']] ?? 0, $row['counter_change_seq']);
                    }
                }

                $this->raiseWallets($horizons);

                return count($deleted);
            });
        }

        return $removed;
    }

    private function purgeOccurrences(DateTimeImmutable $before, int $batchSize): int
    {
        return $this->purgeWalletRows(
            fn (string $afterId): array => $this->store->nextExpiredOccurrences($before, $afterId, $batchSize),
            fn (array $ids): array => $this->store->deleteOccurrences($ids, $before),
        );
    }

    private function purgeRules(DateTimeImmutable $before, int $batchSize): int
    {
        return $this->purgeWalletRows(
            fn (string $afterId): array => $this->store->nextExpiredRules($before, $afterId, $batchSize),
            fn (array $ids): array => $this->store->deleteRules($ids, $before),
        );
    }

    /**
     * Pages tombstones that raise one wallet's horizon each by `change_seq`
     * (an occurrence or a rule), removes what is removable in batches under
     * the wallet locks, and raises the horizons in the same transaction.
     *
     * @param  \Closure(string): list<array{id: string, wallet_id: string}>  $next
     * @param  \Closure(list<string>): list<array{wallet_id: string, change_seq: int}>  $delete
     */
    private function purgeWalletRows(\Closure $next, \Closure $delete): int
    {
        $removed = 0;
        $afterId = '';

        while (($batch = $next($afterId)) !== []) {
            $afterId = $batch[array_key_last($batch)]['id'];

            $removed += DB::transaction(function () use ($batch, $delete): int {
                $this->store->lockWallets(self::ascending(array_column($batch, 'wallet_id')));
                $deleted = $delete(array_column($batch, 'id'));

                $horizons = [];
                foreach ($deleted as $row) {
                    $horizons[$row['wallet_id']] = max($horizons[$row['wallet_id']] ?? 0, $row['change_seq']);
                }

                $this->raiseWallets($horizons);

                return count($deleted);
            });
        }

        return $removed;
    }

    private function purgeReferences(DateTimeImmutable $before, int $batchSize): int
    {
        $removed = 0;
        $afterId = '';

        while (($batch = $this->store->nextExpiredReferences($before, $afterId, $batchSize)) !== []) {
            $afterId = $batch[array_key_last($batch)]['id'];

            $removed += DB::transaction(function () use ($batch, $before): int {
                $this->store->lockWallets(self::ascending(array_column($batch, 'wallet_id')));
                $deleted = $this->store->deleteReferences(array_column($batch, 'id'), $before);

                $horizons = [];
                foreach ($deleted as $row) {
                    $horizons[$row['wallet_id']] = max($horizons[$row['wallet_id']] ?? 0, $row['change_seq']);
                }

                $this->raiseWallets($horizons);

                return count($deleted);
            });
        }

        return $removed;
    }

    private function purgeMembers(DateTimeImmutable $before, int $batchSize): int
    {
        $removed = 0;
        $afterId = '';

        while (($batch = $this->store->nextExpiredMembers($before, $afterId, $batchSize)) !== []) {
            $afterId = $batch[array_key_last($batch)]['id'];

            $removed += DB::transaction(function () use ($batch, $before): int {
                $this->store->lockWallets(self::ascending(array_column($batch, 'wallet_id')));
                $deleted = $this->store->deleteMembers(array_column($batch, 'id'), $before);

                $walletHorizons = [];
                $userHorizons = [];
                foreach ($deleted as $row) {
                    $walletHorizons[$row['wallet_id']] = max($walletHorizons[$row['wallet_id']] ?? 0, $row['change_seq']);
                    $userHorizons[$row['user_id']] = max($userHorizons[$row['user_id']] ?? 0, $row['member_change_seq']);
                }

                $this->raiseWallets($walletHorizons);
                $this->raiseUsers($userHorizons);

                return count($deleted);
            });
        }

        return $removed;
    }

    private function purgeCategories(DateTimeImmutable $before, int $batchSize): int
    {
        $removed = 0;
        $afterId = '';

        while (($batch = $this->store->nextExpiredCategories($before, $afterId, $batchSize)) !== []) {
            $afterId = $batch[array_key_last($batch)]['id'];

            $removed += DB::transaction(function () use ($batch, $before): int {
                $this->store->lockCategories(self::ascending(array_column($batch, 'id')));
                $deleted = $this->store->deleteUnreferencedCategories(array_column($batch, 'id'), $before);

                $horizons = [];
                foreach ($deleted as $row) {
                    $horizons[$row['user_id']] = max($horizons[$row['user_id']] ?? 0, $row['change_seq']);
                }

                $this->raiseUsers($horizons);

                return count($deleted);
            });
        }

        return $removed;
    }

    /**
     * The categories an account purge archived because records still used
     * them (spec 022, FR-046): once nothing refers to one and its owner is
     * gone, it is deleted. Its owner has no reader left, so no tombstone or
     * horizon is needed.
     */
    private function purgeDepartedCategories(int $batchSize): int
    {
        $removed = 0;
        $afterId = '';

        while (($batch = $this->store->nextArchivedCategories($afterId, $batchSize)) !== []) {
            $afterId = $batch[array_key_last($batch)]['id'];
            $departed = array_flip($this->accounts->departedAmong(array_values(array_unique(array_column($batch, 'user_id')))));
            $ids = array_values(array_map(
                fn (array $row): string => $row['id'],
                array_filter($batch, fn (array $row): bool => isset($departed[$row['user_id']])),
            ));

            if ($ids !== []) {
                $removed += DB::transaction(function () use ($ids): int {
                    $this->store->lockCategories(self::ascending($ids));

                    return $this->store->deleteUnreferencedArchivedCategories($ids);
                });
            }
        }

        return $removed;
    }

    private function purgeWallets(DateTimeImmutable $before, int $batchSize): int
    {
        $removed = 0;
        $afterId = '';

        while (($ids = $this->store->nextExpiredWallets($before, $afterId, $batchSize)) !== []) {
            $afterId = $ids[array_key_last($ids)];

            $removed += DB::transaction(function () use ($ids, $before): int {
                $this->store->lockWallets($ids);

                return $this->store->deleteUnreferencedWallets($ids, $before);
            });
        }

        return $removed;
    }

    /**
     * @param  array<string, int>  $horizons  wallet id => position
     */
    private function raiseWallets(array $horizons): void
    {
        ksort($horizons, SORT_STRING);

        foreach ($horizons as $walletId => $position) {
            $this->store->raiseWalletHorizon((string) $walletId, $position);
        }
    }

    /**
     * @param  array<string, int>  $horizons  user id => position
     */
    private function raiseUsers(array $horizons): void
    {
        ksort($horizons, SORT_STRING);

        foreach ($horizons as $userId => $position) {
            $this->identity->raiseHorizon((string) $userId, $position);
        }
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function ascending(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $ids;
    }
}

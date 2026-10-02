<?php

namespace App\Modules\Sync\Application;

use App\Modules\Budgeting\Application\Sync\TombstonePurge as BudgetingPurge;
use App\Modules\Identity\Application\Sync\TombstonePurge as IdentityPurge;
use App\Modules\Sharing\Application\Sync\TombstonePurge as SharingPurge;
use App\Modules\Wallets\Application\Activity\ActivityPrune;
use App\Modules\Wallets\Application\Sync\TombstonePurge as WalletsPurge;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * One run of the daily tombstone purge (spec 013, R-11): removes the
 * syncable tombstones older than the retention through the owning modules'
 * purge services, which raise the purge horizons. Sync owns the retention
 * (security baseline). A configured value under 90 days is clamped to 90 and
 * logged. One summary line per run; no identifiers, no content.
 */
final class TombstonePurgeRun
{
    public const MINIMUM_RETENTION_DAYS = 90;

    public function __construct(
        private readonly WalletsPurge $wallets,
        private readonly IdentityPurge $identity,
        private readonly BudgetingPurge $budgeting,
        private readonly SharingPurge $sharing,
        private readonly ActivityPrune $activity,
    ) {}

    /**
     * @return array<string, int> rows removed per record type
     */
    public function handle(?DateTimeImmutable $now = null): array
    {
        $configured = (int) config('sync.tombstone_retention_days');
        $days = max($configured, self::MINIMUM_RETENTION_DAYS);

        if ($configured < self::MINIMUM_RETENTION_DAYS) {
            Log::warning('sync_retention_clamped', ['configured_days' => $configured, 'applied_days' => $days]);
        }

        $before = ($now ?? now()->toDateTimeImmutable())->modify("-{$days} days");

        // Budgeting first: a category freed by a purged budget can go in the same run.
        // Sharing before Wallets, so a deleted wallet whose last rows were invitation tombstones can go too.
        $removed = [
            ...$this->prefixed('budgeting_', $this->budgeting->purge($before)),
            ...$this->prefixed('sharing_', $this->sharing->purge($before)),
            ...$this->wallets->purge($before),
            'wallets_activity' => $this->activity->prune($now),
            ...$this->prefixed('identity_', $this->identity->purge($before)),
        ];

        Log::info('sync_tombstones_purged', ['retention_days' => $days, 'removed' => $removed]);

        return $removed;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function prefixed(string $prefix, array $counts): array
    {
        $prefixed = [];

        foreach ($counts as $type => $count) {
            $prefixed[$prefix.$type] = $count;
        }

        return $prefixed;
    }
}

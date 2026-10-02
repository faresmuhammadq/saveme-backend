<?php

namespace App\Modules\Wallets\Application\Activity;

use App\Modules\Wallets\Domain\Ports\ActivityLog;
use DateTimeImmutable;

/**
 * The activity's side of the daily purge (spec 016, ADR-0020 rule 7): removes
 * entries older than the retention, 90 days at least, in batches of 1,000.
 * Idempotent and restartable.
 */
final class ActivityPrune
{
    public const MINIMUM_RETENTION_DAYS = 90;

    public function __construct(private readonly ActivityLog $log) {}

    public function prune(?DateTimeImmutable $now = null, int $batchSize = 1000): int
    {
        $days = max((int) config('sharing.activity_retention_days'), self::MINIMUM_RETENTION_DAYS);
        $before = ($now ?? now()->toDateTimeImmutable())->modify("-{$days} days");
        $removed = 0;

        while (($count = $this->log->pruneBefore($before, $batchSize)) > 0) {
            $removed += $count;
        }

        return $removed;
    }
}

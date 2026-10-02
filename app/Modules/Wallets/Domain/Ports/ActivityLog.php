<?php

namespace App\Modules\Wallets\Domain\Ports;

use App\Modules\Wallets\Domain\Activity\ActivityAction;
use App\Modules\Wallets\Domain\Activity\ActivityRecord;
use App\Modules\Wallets\Domain\Activity\ActivitySubject;
use App\Modules\Wallets\Domain\Membership\Role;
use DateTimeImmutable;

/**
 * The wallet's activity, append-only (ADR-0020): there is no update path.
 */
interface ActivityLog
{
    /**
     * Inserts one entry stamped with the write's instant. Called inside the
     * unit of work of the write it records.
     */
    public function record(string $walletId, ?string $actorId, ActivityAction $action, ActivitySubject $subject, string $subjectId, ?Role $role): void;

    /**
     * Newest first by (`occurred_at`, `id`), one page plus one.
     *
     * @param  array{value: string, id: string}|null  $after  the last entry of the previous page
     * @return list<ActivityRecord>
     */
    public function page(string $walletId, ?array $after, int $limit): array;

    /**
     * Removes up to $batch entries older than the cut; returns how many went.
     */
    public function pruneBefore(DateTimeImmutable $before, int $batch): int;
}

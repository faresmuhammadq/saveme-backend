<?php

namespace App\Modules\Identity\Domain\Ports;

use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use DateTimeImmutable;

/**
 * Purge progress, one row per account being purged (data-model:
 * account_purges).
 */
interface AccountPurgeRepository
{
    /**
     * Claims the right to run this account's purge, for the life of the
     * database session: at most one run per account at a time (FR-053). A
     * run that dies releases it with its connection.
     *
     * @return bool whether this caller now holds the claim
     */
    public function tryClaim(string $userId): bool;

    /**
     * Gives the claim back.
     */
    public function release(string $userId): void;

    /**
     * Opens the progress row at `begin` if there is none; counts an attempt.
     */
    public function begin(string $userId, DateTimeImmutable $at): void;

    /**
     * @return array{last_step: PurgeStep, counts: PurgeCounts, started_at: DateTimeImmutable, mail_sent_at: ?DateTimeImmutable, stall_notified_at: ?DateTimeImmutable}|null
     */
    public function find(string $userId): ?array;

    /**
     * Records a completed step and replaces the accumulated counts.
     */
    public function record(string $userId, PurgeStep $step, PurgeCounts $counts, DateTimeImmutable $at): void;

    /**
     * Stamps the completion mail as sent, only the first time.
     *
     * @return bool whether this call stamped it
     */
    public function markMailSent(string $userId, DateTimeImmutable $at): bool;

    public function delete(string $userId): void;

    /**
     * Purges that began before the cut and have not been reported.
     *
     * @return list<array{user_id: string, started_at: DateTimeImmutable}>
     */
    public function stalledBefore(DateTimeImmutable $cut): array;

    /**
     * Stamps the stall as reported, only the first time.
     */
    public function markStallNotified(string $userId, DateTimeImmutable $at): bool;
}

<?php

namespace App\Modules\Notifications\Domain\Ports;

use App\Modules\Notifications\Domain\Delivery\NotificationRecord;
use App\Modules\Notifications\Domain\Delivery\SkipReason;
use App\Modules\Notifications\Domain\Messages\NotificationDraft;
use DateTimeImmutable;

interface NotificationRepository
{
    /**
     * Inserts the draft as `pending` (or `skipped` when a reason is given)
     * unless the person already has a notification under its dedupe key.
     *
     * @return NotificationRecord|null null when the key existed
     */
    public function createIfAbsent(NotificationDraft $draft, ?SkipReason $skipped = null): ?NotificationRecord;

    public function find(string $id): ?NotificationRecord;

    /**
     * As find(), taking the row lock.
     */
    public function lockForDelivery(string $id): ?NotificationRecord;

    public function markHeld(string $id, DateTimeImmutable $notBefore): void;

    public function markSending(string $id, DateTimeImmutable $since): void;

    public function markSent(string $id, DateTimeImmutable $at): void;

    public function markSkipped(string $id, SkipReason $reason): void;

    public function markFailed(string $id): void;

    /**
     * Held notifications whose release time has come, oldest first.
     *
     * @return list<NotificationRecord>
     */
    public function dueHeld(DateTimeImmutable $now, int $limit): array;

    /**
     * Every held notification, for the release sweep to re-evaluate against
     * current preferences (a person may have moved or turned off quiet hours).
     *
     * @return list<NotificationRecord>
     */
    public function allHeld(int $limit): array;

    /**
     * Notifications left `sending` since before the cut.
     *
     * @return list<NotificationRecord>
     */
    public function staleSending(DateTimeImmutable $before, int $limit): array;

    /**
     * Notifications sent (or being sent) to the person since the instant,
     * not counting the one being judged.
     */
    public function sentCountSince(string $userId, DateTimeImmutable $since, ?string $exceptId = null): int;

    /**
     * Which of the dedupe keys the person already has.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function existingKeys(string $userId, array $keys): array;

    public function deleteCreatedBefore(DateTimeImmutable $cut): int;

    public function deleteAllFor(string $userId): int;

    /**
     * Counts by state for notifications created since the instant.
     *
     * @return array{created: int, sent: int, skipped: int, failed: int}
     */
    public function figuresSince(DateTimeImmutable $since): array;
}

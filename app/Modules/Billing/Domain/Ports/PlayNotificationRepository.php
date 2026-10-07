<?php

namespace App\Modules\Billing\Domain\Ports;

use App\Modules\Billing\Domain\GooglePlay\StoredPlayNotification;
use DateTimeImmutable;

/**
 * The Play Notification inbox (spec 024, FR-034). The Pub/Sub message id is unique.
 */
interface PlayNotificationRepository
{
    /**
     * Stores a verified notification unless its message id is known.
     *
     * @param  array{id: string, message_id: string, kind: string, notification_type: ?int, product_id: ?string, token_lookup: ?string, token_sealed: ?string, event_at: ?DateTimeImmutable, published_at: ?DateTimeImmutable, received_at: DateTimeImmutable, state: string, ignored_reason: ?string}  $row
     * @return bool whether this call stored it (false: a repeat)
     */
    public function insertIfAbsent(array $row): bool;

    public function find(string $id): ?StoredPlayNotification;

    public function lock(string $id): ?StoredPlayNotification;

    public function incrementAttempts(string $id): void;

    public function markProcessed(string $id): void;

    public function markIgnored(string $id, string $reason): void;

    public function markFailed(string $id): void;

    public function deleteReceivedBefore(DateTimeImmutable $cut): int;

    /**
     * Notifications received since $since, by state.
     *
     * @return array<string, int>
     */
    public function countsSince(DateTimeImmutable $since): array;
}

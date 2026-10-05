<?php

namespace App\Modules\Admin\Domain\Ports;

use App\Modules\Admin\Domain\Notifications\OperatorNotificationView;
use DateTimeImmutable;

/**
 * Stored operator notifications (spec 007, R-14).
 */
interface OperatorNotificationStore
{
    /**
     * Stores a notification unless its source event was already recorded.
     *
     * @param  array<string, scalar|null>  $payload
     * @return bool whether this call stored it
     */
    public function recordOnce(string $kind, string $subjectUserId, DateTimeImmutable $occurredAt, array $payload, string $sourceEventId): bool;

    /**
     * The newest notifications with this operator's read mark on each.
     *
     * @return list<OperatorNotificationView>
     */
    public function latestFor(string $operatorId, int $limit): array;

    /**
     * Notifications since $since that this operator has not marked read.
     */
    public function unreadCountFor(string $operatorId, DateTimeImmutable $since): int;

    /**
     * Marks one notification read for one operator; idempotent, and a
     * notification that does not exist is ignored.
     */
    public function markRead(string $operatorId, string $notificationId, DateTimeImmutable $at): void;

    /**
     * Marks every notification since $since that this operator has not read
     * as read for them; idempotent.
     */
    public function markAllRead(string $operatorId, DateTimeImmutable $since, DateTimeImmutable $at): void;
}

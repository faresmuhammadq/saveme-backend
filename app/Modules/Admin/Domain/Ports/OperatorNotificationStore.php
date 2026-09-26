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
     * @return list<OperatorNotificationView>
     */
    public function latest(int $limit): array;

    public function countSince(DateTimeImmutable $since): int;
}

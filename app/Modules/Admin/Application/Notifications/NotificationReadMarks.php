<?php

namespace App\Modules\Admin\Application\Notifications;

use App\Modules\Admin\Domain\Ports\OperatorNotificationStore;

/**
 * Each operator's own read marks on operator notifications (spec 021,
 * FR-050 to FR-053). Interface state, not an act on a person: both calls are
 * idempotent and neither writes an audit entry.
 */
final class NotificationReadMarks
{
    public function __construct(private readonly OperatorNotificationStore $store) {}

    public function markRead(string $operatorId, string $notificationId): void
    {
        $this->store->markRead($operatorId, $notificationId, now()->toDateTimeImmutable());
    }

    public function markAllRead(string $operatorId): void
    {
        $this->store->markAllRead(
            $operatorId,
            now()->subDays((int) config('admin.notifications.unread_days'))->toDateTimeImmutable(),
            now()->toDateTimeImmutable(),
        );
    }
}

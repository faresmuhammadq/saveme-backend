<?php

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Domain\Notifications\OperatorNotificationView;
use App\Modules\Admin\Domain\Ports\OperatorNotificationStore;

/**
 * What the back office's notification bell reads (P-18 rule 5). "Unread" is
 * per operator: a notification from the last `admin.notifications.unread_days`
 * days that this operator has not marked read (spec 021, FR-051).
 */
final class OperatorNotificationQueryService
{
    public function __construct(private readonly OperatorNotificationStore $store) {}

    /**
     * @return list<OperatorNotificationView>
     */
    public function latestFor(string $operatorId, int $limit = 20): array
    {
        return $this->store->latestFor($operatorId, $limit);
    }

    public function unreadCountFor(string $operatorId): int
    {
        return $this->store->unreadCountFor($operatorId, now()->subDays((int) config('admin.notifications.unread_days'))->toDateTimeImmutable());
    }
}

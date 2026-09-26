<?php

namespace App\Modules\Admin\Application;

use App\Modules\Admin\Domain\Notifications\OperatorNotificationView;
use App\Modules\Admin\Domain\Ports\OperatorNotificationStore;

/**
 * What the back office's notification bell reads (P-18 rule 5). Until
 * operator accounts carry read state (D2), "unread" means recent.
 */
final class OperatorNotificationQueryService
{
    public function __construct(private readonly OperatorNotificationStore $store) {}

    /**
     * @return list<OperatorNotificationView>
     */
    public function latest(int $limit = 20): array
    {
        return $this->store->latest($limit);
    }

    public function unreadCount(): int
    {
        return $this->store->countSince(now()->subDays((int) config('admin.notifications.unread_days'))->toDateTimeImmutable());
    }
}

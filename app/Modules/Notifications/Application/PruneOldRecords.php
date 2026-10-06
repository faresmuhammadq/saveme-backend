<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Ports\NotificationRepository;
use Illuminate\Support\Facades\Log;

/**
 * Deletes notifications (and their deliveries, by cascade) 30 days after
 * creation (spec 023, FR-043). The idle-registration prune lives in
 * DeviceService; the daily command runs both and logs one counts-only line.
 */
final class PruneOldRecords
{
    public function __construct(
        private readonly DeviceService $devices,
        private readonly NotificationRepository $notifications,
    ) {}

    /**
     * @return array{registrations: int, notifications: int}
     */
    public function run(): array
    {
        $now = now()->toDateTimeImmutable();
        $days = (int) config('notifications.retention_days');

        $counts = [
            'registrations' => $this->devices->pruneIdle($now),
            'notifications' => $this->notifications->deleteCreatedBefore($now->modify("-{$days} days")),
        ];

        Log::info('notifications_pruned', $counts);

        return $counts;
    }
}

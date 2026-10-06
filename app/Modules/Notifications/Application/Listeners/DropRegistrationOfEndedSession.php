<?php

namespace App\Modules\Notifications\Application\Listeners;

use App\Modules\Identity\Domain\Events\DeviceSessionEnded;
use App\Modules\Notifications\Application\DeviceService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A session ended, for any reason: its push registration goes (spec 023,
 * FR-009). Queued and idempotent; the delivery-time guard covers the window
 * before this runs (FR-011).
 */
final class DropRegistrationOfEndedSession implements ShouldQueue
{
    public string $queue = 'notifications';

    public function __construct(private readonly DeviceService $devices) {}

    public function handle(DeviceSessionEnded $event): void
    {
        $this->devices->dropForSession($event->sessionId);
    }
}

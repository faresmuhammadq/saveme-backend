<?php

namespace App\Modules\Identity\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A session stopped working. Notifications drops its push registration (B16).
 *
 * Dispatched after commit, carrying identifiers and scalars only (P-11).
 * `eventId` lets every consumer deduplicate a redelivery.
 */
final readonly class DeviceSessionEnded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        public string $sessionId,
        public string $reason,
    ) {}
}

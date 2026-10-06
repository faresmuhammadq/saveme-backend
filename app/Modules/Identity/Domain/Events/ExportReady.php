<?php

namespace App\Modules\Identity\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An export reached `ready` (spec 023, FR-027). Notifications tells the
 * requester; the link stays in the mail. Dispatched after commit, identifiers
 * only (P-11); `eventId` lets a consumer deduplicate a redelivery.
 */
final readonly class ExportReady implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        public string $exportId,
    ) {}
}

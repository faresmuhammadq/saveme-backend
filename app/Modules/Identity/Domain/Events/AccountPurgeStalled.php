<?php

namespace App\Modules\Identity\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An account has been `purging` for longer than the stall limit (spec 022,
 * FR-063). Carries the user id and the instant the purge began, nothing
 * else (P-11); `eventId` lets the back office deduplicate a redelivery.
 */
final readonly class AccountPurgeStalled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        public string $startedAt,
    ) {}
}

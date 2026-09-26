<?php

namespace App\Modules\Identity\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The account entered the deletion grace period; the purge (B15) runs at scheduledFor.
 *
 * Dispatched after commit, carrying identifiers and scalars only (P-11).
 * `eventId` lets every consumer deduplicate a redelivery.
 */
final readonly class UserDeletionRequested implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        public string $scheduledFor,
    ) {}
}

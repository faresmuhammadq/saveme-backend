<?php

namespace App\Modules\Identity\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A pending deletion was cancelled, explicitly or by a new sign-in. Admin notifies operators of the sign-in case.
 *
 * Dispatched after commit, carrying identifiers and scalars only (P-11).
 * `eventId` lets every consumer deduplicate a redelivery.
 */
final readonly class UserDeletionCancelled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        /** @var 'explicit'|'sign_in' */
        public string $reason,
        public ?string $sessionId = null,
    ) {}
}

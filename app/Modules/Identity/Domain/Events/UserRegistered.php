<?php

namespace App\Modules\Identity\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A new account exists: user, profile and default subscription committed together.
 *
 * Dispatched after commit, carrying identifiers and scalars only (P-11).
 * `eventId` lets every consumer deduplicate a redelivery.
 */
final readonly class UserRegistered implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        public string $locale,
    ) {}
}

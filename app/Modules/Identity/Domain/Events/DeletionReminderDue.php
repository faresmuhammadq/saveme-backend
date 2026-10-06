<?php

namespace App\Modules\Identity\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An account is three days or less from its purge and was just stamped as
 * reminded (spec 023, FR-027). `deletionScheduledFor` (ISO-8601 UTC) keeps a
 * later request distinct from this one. Dispatched after commit, identifiers
 * and scalars only (P-11).
 */
final readonly class DeletionReminderDue implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $userId,
        public string $deletionScheduledFor,
    ) {}
}

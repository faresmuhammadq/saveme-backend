<?php

namespace App\Modules\Sharing\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A member's membership ended: `removed` by the owner or `left` by the member.
 * Dispatched after commit, identifiers and scalars only (P-11).
 */
final readonly class MemberRevoked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $walletId,
        public string $memberUserId,
        public string $actorUserId,
        public string $reason,
    ) {}
}

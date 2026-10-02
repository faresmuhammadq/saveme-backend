<?php

namespace App\Modules\Sharing\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An invitation was accepted and the person joined the wallet. Dispatched
 * after commit, identifiers and scalars only (P-11).
 */
final readonly class InvitationAccepted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $invitationId,
        public string $walletId,
        public string $memberUserId,
        public string $role,
    ) {}
}

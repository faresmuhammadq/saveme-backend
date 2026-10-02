<?php

namespace App\Modules\Sharing\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An invitation was created. Dispatched after commit, identifiers and scalars
 * only (P-11): never the address or the token.
 */
final readonly class InvitationSent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $invitationId,
        public string $walletId,
        public string $invitedBy,
        public string $channel,
        public string $role,
    ) {}
}

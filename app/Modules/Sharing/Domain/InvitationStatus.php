<?php

namespace App\Modules\Sharing\Domain;

/**
 * An invitation's state machine (glossary): `pending` while live, then one of
 * four closed states, each a tombstone. Nothing leaves a closed state.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function isClosed(): bool
    {
        return $this !== self::Pending;
    }

    public function canBecome(self $next): bool
    {
        return $this === self::Pending && $next->isClosed();
    }
}

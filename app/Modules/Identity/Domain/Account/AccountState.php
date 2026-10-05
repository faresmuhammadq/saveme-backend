<?php

namespace App\Modules\Identity\Domain\Account;

/**
 * A User's lifecycle (glossary): active ⇄ pending_deletion → purging → purged. `purging` is the purge
 * under way (no sign-in, no cancel); `purged` is a husk holding no identity
 * attribute (spec 022).
 */
enum AccountState: string
{
    case Active = 'active';
    case PendingDeletion = 'pending_deletion';
    case Purging = 'purging';
    case Purged = 'purged';

    /**
     * Whether the person can no longer be found by address: the purge is
     * under way or finished, so the account is treated as unknown.
     */
    public function isGone(): bool
    {
        return $this === self::Purging || $this === self::Purged;
    }
}

<?php

namespace App\Modules\Identity\Domain\Account;

/**
 * A User's lifecycle (glossary): active ⇄ pending_deletion → purged.
 */
enum AccountState: string
{
    case Active = 'active';
    case PendingDeletion = 'pending_deletion';
    case Purged = 'purged';
}

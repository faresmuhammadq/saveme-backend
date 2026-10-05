<?php

namespace App\Modules\Identity\Application\Mail;

/**
 * The account has been deleted (spec 022, FR-059): sent from inside the
 * purge, before the address is destroyed. Says what ended, that a Google
 * Play subscription is not cancelled by it, and names no wallet, amount or
 * other person (FR-060).
 */
final class AccountDeletedMail extends IdentityMail
{
    protected function key(): string
    {
        return 'account_deleted';
    }
}

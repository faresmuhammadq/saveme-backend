<?php

namespace App\Modules\Identity\Application\Mail;

/**
 * The password was changed from a signed-in device; other devices were
 * signed out. Advises a reset if it was not the owner.
 */
final class PasswordChangedMail extends IdentityMail
{
    protected function key(): string
    {
        return 'password_changed';
    }
}

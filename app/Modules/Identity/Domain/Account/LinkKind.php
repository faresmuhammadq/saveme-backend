<?php

namespace App\Modules\Identity\Domain\Account;

/**
 * The two single-use mailed links (security baseline, Reset and verification
 * tokens), with their lifetimes.
 */
enum LinkKind: string
{
    case VerifyAddress = 'verify_address';
    case ResetPassword = 'reset_password';

    public function lifetimeMinutes(): int
    {
        return match ($this) {
            self::VerifyAddress => 24 * 60,
            self::ResetPassword => 60,
        };
    }
}

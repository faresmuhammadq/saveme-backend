<?php

namespace App\Modules\Identity\Application\Passwords;

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Domain\Password\PasswordPolicy;
use App\Modules\Identity\Domain\Refusals\InvalidPassword;

/**
 * Applies the password policy (FR-006) to a new password for an account.
 */
final class NewPassword
{
    public function __construct(private readonly Accounts $accounts) {}

    public function assertAcceptable(string $userId, string $password, string $member): void
    {
        if (PasswordPolicy::violations($password, $this->accounts->address($userId)) !== []) {
            throw new InvalidPassword($member);
        }
    }
}

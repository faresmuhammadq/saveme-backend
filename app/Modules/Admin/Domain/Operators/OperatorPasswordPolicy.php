<?php

namespace App\Modules\Admin\Domain\Operators;

use App\Modules\Identity\Domain\Address\AddressNormaliser;

/**
 * Operator passwords are stricter than users': 12 to 128 characters, not the
 * operator's email, not the current or temporary password (spec 018, R-15).
 */
final class OperatorPasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const MAX_LENGTH = 128;

    /**
     * @param  bool  $matchesCurrent  whether the candidate equals the password now on the account (the temporary one, or the one being replaced)
     * @return list<'too_short'|'too_long'|'equals_email'|'reused'>
     */
    public static function violations(string $password, string $email, bool $matchesCurrent = false): array
    {
        $violations = [];
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $violations[] = 'too_short';
        }

        if ($length > self::MAX_LENGTH) {
            $violations[] = 'too_long';
        }

        if (AddressNormaliser::normalise($password) === AddressNormaliser::normalise($email)) {
            $violations[] = 'equals_email';
        }

        if ($matchesCurrent) {
            $violations[] = 'reused';
        }

        return $violations;
    }
}

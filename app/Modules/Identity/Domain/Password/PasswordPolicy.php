<?php

namespace App\Modules\Identity\Domain\Password;

use App\Modules\Identity\Domain\Address\AddressNormaliser;

/**
 * FR-006: 8 to 128 characters, no composition rules, and not the address
 * itself. The upper bound stops pathological input reaching the hash.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 128;

    /**
     * @return list<'too_short'|'too_long'|'equals_address'>
     */
    public static function violations(string $password, string $address): array
    {
        $violations = [];
        $length = mb_strlen($password);

        if ($length < self::MIN_LENGTH) {
            $violations[] = 'too_short';
        }

        if ($length > self::MAX_LENGTH) {
            $violations[] = 'too_long';
        }

        if (AddressNormaliser::normalise($password) === AddressNormaliser::normalise($address)) {
            $violations[] = 'equals_address';
        }

        return $violations;
    }
}

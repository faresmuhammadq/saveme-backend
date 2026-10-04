<?php

namespace App\Modules\Admin\Domain\Operators;

use App\Modules\Admin\Domain\SecondFactor\RecoveryCode;

/**
 * The generated password the command line prints once: 20 characters from the
 * unambiguous alphabet (spec 018, R-11, R-14).
 */
final class TemporaryPassword
{
    public const LENGTH = 20;

    public static function generate(): string
    {
        $password = '';

        for ($index = 0; $index < self::LENGTH; $index++) {
            $password .= RecoveryCode::ALPHABET[random_int(0, strlen(RecoveryCode::ALPHABET) - 1)];
        }

        return $password;
    }
}

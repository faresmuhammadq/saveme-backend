<?php

namespace App\Modules\Billing\Domain\Claims;

/**
 * The InstaPay transaction reference as claimed (spec 020, FR-006): spaces
 * and hyphens removed, letters upper-cased, then 8 to 32 characters of A-Z
 * and 0-9. The normalised form is what is stored and compared.
 */
final class ClaimReference
{
    public const PATTERN = '/^[A-Z0-9]{8,32}$/';

    public static function normalise(string $raw): string
    {
        return mb_strtoupper(str_replace([' ', '-'], '', $raw));
    }

    public static function isValid(string $raw): bool
    {
        return preg_match(self::PATTERN, self::normalise($raw)) === 1;
    }
}

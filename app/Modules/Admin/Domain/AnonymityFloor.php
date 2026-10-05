<?php

namespace App\Modules\Admin\Domain;

use InvalidArgumentException;

/**
 * Anonymity Floor (glossary; spec 021, R-11): the smallest non-zero
 * population count the back office shows exactly. Smaller non-zero counts
 * are shown as "fewer than 10". Zero is exact, because it names nobody.
 */
final class AnonymityFloor
{
    public const FLOOR = 10;

    public const MARKER = 'fewer_than_10';

    public static function display(int $count): int|string
    {
        if ($count < 0) {
            throw new InvalidArgumentException('A population count cannot be negative.');
        }

        return $count === 0 || $count >= self::FLOOR ? $count : self::MARKER;
    }
}

<?php

namespace App\Modules\Identity\Domain\Preferences;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The day of the month a person's budget periods begin ("salary day"), 1–28
 * so every month has it (spec 008, FR-011, FR-013).
 */
final class BudgetStartDay
{
    public const MIN = 1;

    public const MAX = 28;

    /**
     * The first calendar date strictly after $today whose day of the month is
     * $day: where a changed start day takes over. Periods already begun keep
     * their dates; the arithmetic built on this belongs to Budgeting (B8).
     *
     * @return string YYYY-MM-DD
     */
    public static function appliesFrom(int $day, DateTimeImmutable $today): string
    {
        if ($day < self::MIN || $day > self::MAX) {
            throw new InvalidArgumentException("A budget start day is 1 to 28, not {$day}.");
        }

        $month = $today->modify('first day of this month');

        if ((int) $today->format('j') >= $day) {
            $month = $month->modify('first day of next month');
        }

        $candidate = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day);

        return $candidate->format('Y-m-d');
    }
}

<?php

namespace App\Modules\Budgeting\Domain;

use DateTimeImmutable;

/**
 * Budget Period arithmetic over the start day history (spec 015, FR-008,
 * research R-3). Pure: calendar dates in, calendar dates out.
 */
final class BudgetPeriods
{
    /**
     * The Budget Period containing $date.
     *
     * @param  string  $date  YYYY-MM-DD
     * @param  list<StartDayEntry>  $entries  the history; empty means one implicit entry of $fallbackDay
     */
    public static function containing(string $date, array $entries, int $fallbackDay = 1): BudgetPeriod
    {
        $entries = self::ordered($entries === [] ? [new StartDayEntry($fallbackDay, null)] : $entries);

        $index = 0;

        foreach ($entries as $position => $entry) {
            if ($entry->appliesFrom !== null && $entry->appliesFrom <= $date) {
                $index = $position;
            }
        }

        $governing = $entries[$index];
        $next = $entries[$index + 1] ?? null;

        $day = $governing->startDay;
        $start = self::latestStartOnOrBefore($date, $day);

        if ($governing->appliesFrom !== null && $governing->appliesFrom <= $date && $start < $governing->appliesFrom) {
            $start = $governing->appliesFrom;
        }

        $end = self::dayBefore(self::nextStartAfter($start, $day));

        if ($next !== null && $next->appliesFrom !== null && self::dayBefore($next->appliesFrom) < $end) {
            $end = self::dayBefore($next->appliesFrom);
        }

        return new BudgetPeriod($start, $end);
    }

    /**
     * @param  list<StartDayEntry>  $entries
     * @return list<StartDayEntry> the null entry first, then by `applies_from`
     */
    private static function ordered(array $entries): array
    {
        usort($entries, fn (StartDayEntry $a, StartDayEntry $b): int => ($a->appliesFrom ?? '') <=> ($b->appliesFrom ?? ''));

        return $entries;
    }

    private static function latestStartOnOrBefore(string $date, int $day): string
    {
        $d = new DateTimeImmutable($date);
        $month = $d->modify('first day of this month');

        if ((int) $d->format('j') < $day) {
            $month = $month->modify('first day of last month');
        }

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)->format('Y-m-d');
    }

    private static function nextStartAfter(string $start, int $day): string
    {
        $month = (new DateTimeImmutable($start))->modify('first day of next month');

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)->format('Y-m-d');
    }

    private static function dayBefore(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }
}

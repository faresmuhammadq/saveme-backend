<?php

namespace App\Modules\Wallets\Domain\Recurring;

/**
 * The dates a recurring rule gives (spec 014, FR-010, research R-4): pure
 * calendar arithmetic on `YYYY-MM-DD` strings, no timezone, no clock.
 *
 * Every date is computed from the origin index k, never from the previous
 * date, so a month-end clamp never drags later months with it:
 * - daily: start + k·interval days;
 * - weekly: start + 7·k·interval days (the start's weekday);
 * - monthly: month of start + k·interval, on the anchor day clamped to the
 *   month's last day;
 * - yearly: year of start + k·interval, the start's month, the anchor day
 *   clamped.
 *
 * Dates before the start (only the first monthly or yearly date can be) are
 * dropped and rounds are numbered from 1 over what remains. The series stops
 * after the end date or the count-th round; a skipped round still counts.
 */
final readonly class RuleDates
{
    public const FIRST_DATE = '1900-01-01';

    public const LAST_DATE = '2999-12-31';

    private int $startDays;

    private int $startYear;

    private int $startMonth;

    private int $startDay;

    /** Whether the first computed date falls before the start and is dropped. */
    private bool $firstDropped;

    public function __construct(
        public Frequency $frequency,
        public int $interval,
        public ?int $anchorDay,
        public string $startDate,
        public ?string $endDate,
        public ?int $count,
    ) {
        $this->startDays = self::days($startDate);
        [$this->startYear, $this->startMonth, $this->startDay] = self::parts($startDate);
        $this->firstDropped = ($this->dateAt(0) ?? self::LAST_DATE) < $startDate;
    }

    /**
     * The rounds dated from $from to $to, both inclusive, in date order, at
     * most $cap of them.
     *
     * @return list<array{ordinal: int, date: string}>
     */
    public function between(string $from, string $to, int $cap): array
    {
        $rounds = [];

        if ($cap < 1) {
            return $rounds;
        }

        for ($index = $this->firstIndexOnOrAfter($from); ; $index++) {
            $date = $this->dateAt($index);

            if ($date === null || $date > $to || ($this->endDate !== null && $date > $this->endDate)) {
                break;
            }

            if ($date < $this->startDate) {
                continue;
            }

            $ordinal = $index - ($this->firstDropped ? 1 : 0) + 1;

            if ($this->count !== null && $ordinal > $this->count) {
                break;
            }

            if ($date < $from) {
                continue;
            }

            $rounds[] = ['ordinal' => $ordinal, 'date' => $date];

            if (count($rounds) >= $cap) {
                break;
            }
        }

        return $rounds;
    }

    /**
     * The first round dated after $date, or null when the series ends first.
     *
     * @return array{ordinal: int, date: string}|null
     */
    public function nextAfter(string $date): ?array
    {
        if ($date >= self::LAST_DATE) {
            return null;
        }

        return $this->between(self::addDays($date, 1), self::LAST_DATE, 1)[0] ?? null;
    }

    /**
     * The round number of a date the schedule gives, or null when the date is
     * not one of its rounds (off the pattern, before the start, past the end).
     */
    public function ordinalOf(string $date): ?int
    {
        $index = $this->indexOf($date);

        if ($index === null || $date < $this->startDate) {
            return null;
        }

        $ordinal = $index - ($this->firstDropped ? 1 : 0) + 1;

        if (($this->endDate !== null && $date > $this->endDate) || ($this->count !== null && $ordinal > $this->count)) {
            return null;
        }

        return $ordinal;
    }

    public function contains(string $date): bool
    {
        return $this->ordinalOf($date) !== null;
    }

    /**
     * Whether the date lies beyond the series' last round (its end date, or
     * the count-th round's date), whether or not it is on the pattern.
     */
    public function isAfterEnd(string $date): bool
    {
        if ($this->endDate !== null && $date > $this->endDate) {
            return true;
        }

        if ($this->count !== null) {
            $last = $this->dateAt($this->count - 1 + ($this->firstDropped ? 1 : 0));

            return $last !== null && $date > $last;
        }

        return false;
    }

    private function dateAt(int $index): ?string
    {
        $anchor = $this->anchorDay ?? $this->startDay;
        $months = $this->startYear * 12 + ($this->startMonth - 1) + $index * $this->interval;

        return match ($this->frequency) {
            Frequency::Daily => self::fromDays($this->startDays + $index * $this->interval),
            Frequency::Weekly => self::fromDays($this->startDays + 7 * $index * $this->interval),
            Frequency::Monthly => self::clamped(intdiv($months, 12), $months % 12 + 1, $anchor),
            Frequency::Yearly => self::clamped($this->startYear + $index * $this->interval, $this->startMonth, $anchor),
        };
    }

    private function indexOf(string $date): ?int
    {
        [$year, $month] = self::parts($date);

        if ($this->frequency === Frequency::Yearly && $month !== $this->startMonth) {
            return null;
        }

        $diff = match ($this->frequency) {
            Frequency::Daily, Frequency::Weekly => self::days($date) - $this->startDays,
            Frequency::Monthly => ($year * 12 + $month) - ($this->startYear * 12 + $this->startMonth),
            Frequency::Yearly => $year - $this->startYear,
        };
        $step = match ($this->frequency) {
            Frequency::Weekly => 7 * $this->interval,
            default => $this->interval,
        };

        if ($diff < 0 || $diff % $step !== 0) {
            return null;
        }

        $index = intdiv($diff, $step);

        return $this->dateAt($index) === $date ? $index : null;
    }

    /**
     * An index at or a little before the first date on or after $from, so the
     * walk never starts years early.
     */
    private function firstIndexOnOrAfter(string $from): int
    {
        if ($from <= $this->startDate) {
            return 0;
        }

        [$year, $month] = self::parts($from);

        return match ($this->frequency) {
            Frequency::Daily => max(0, intdiv(self::days($from) - $this->startDays + $this->interval - 1, $this->interval)),
            Frequency::Weekly => max(0, intdiv(self::days($from) - $this->startDays + 7 * $this->interval - 1, 7 * $this->interval)),
            Frequency::Monthly => max(0, intdiv(($year * 12 + $month) - ($this->startYear * 12 + $this->startMonth), $this->interval) - 1),
            Frequency::Yearly => max(0, intdiv($year - $this->startYear, $this->interval) - 1),
        };
    }

    private static function clamped(int $year, int $month, int $day): ?string
    {
        if ($year > 2999 || $year < 1900) {
            return null;
        }

        $last = (int) gmdate('t', (int) gmmktime(0, 0, 0, $month, 1, $year));

        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last));
    }

    public static function addDays(string $date, int $days): string
    {
        return self::fromDays(self::days($date) + $days) ?? self::LAST_DATE;
    }

    private static function days(string $date): int
    {
        [$year, $month, $day] = self::parts($date);

        return intdiv((int) gmmktime(0, 0, 0, $month, $day, $year), 86400);
    }

    private static function fromDays(int $days): ?string
    {
        $date = gmdate('Y-m-d', $days * 86400);

        return $date > self::LAST_DATE ? null : $date;
    }

    /**
     * @return array{int, int, int}
     */
    private static function parts(string $date): array
    {
        return [(int) substr($date, 0, 4), (int) substr($date, 5, 2), (int) substr($date, 8, 2)];
    }
}

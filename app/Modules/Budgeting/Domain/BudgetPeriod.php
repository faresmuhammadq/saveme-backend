<?php

namespace App\Modules\Budgeting\Domain;

use DateTimeImmutable;

/**
 * A window of calendar dates, both ends inclusive (spec 015, FR-008). Never
 * an instant, never stored.
 */
final readonly class BudgetPeriod
{
    /**
     * @param  string  $start  YYYY-MM-DD
     * @param  string  $end  YYYY-MM-DD, inclusive
     */
    public function __construct(
        public string $start,
        public string $end,
    ) {}

    public function daysInPeriod(): int
    {
        return (int) (new DateTimeImmutable($this->start))->diff(new DateTimeImmutable($this->end))->days + 1;
    }

    public function contains(string $date): bool
    {
        return $date >= $this->start && $date <= $this->end;
    }

    /**
     * Days from the start through $today inclusive; null when $today is outside the period.
     */
    public function daysElapsed(string $today): ?int
    {
        if (! $this->contains($today)) {
            return null;
        }

        return (int) (new DateTimeImmutable($this->start))->diff(new DateTimeImmutable($today))->days + 1;
    }
}

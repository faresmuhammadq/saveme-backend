<?php

namespace App\Modules\Budgeting\Application;

use App\Modules\Budgeting\Application\Dto\BudgetPeriodSpan;
use App\Modules\Budgeting\Domain\BudgetPeriod;
use App\Modules\Budgeting\Domain\BudgetPeriods;
use App\Modules\Budgeting\Domain\StartDayEntry;
use App\Modules\Identity\Application\Profiles\BudgetStartDayQuery;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The one code path for "today" and "the Budget Period containing a date" of
 * a person (spec 015 FR-008, FR-009; spec 019 research R-5): budgets and
 * reports both call it, so a report's default Report Range equals the dates
 * `/budget-progress` shows. Periods come from the person's budget start day
 * history; "today" is in their profile timezone.
 */
final class BudgetPeriodQuery
{
    public function __construct(private readonly BudgetStartDayQuery $startDays) {}

    /**
     * Today in the person's profile timezone (the configured default without a profile).
     *
     * @return string YYYY-MM-DD
     */
    public function todayOf(string $userId): string
    {
        return now()->toDateTimeImmutable()->setTimezone(new DateTimeZone($this->startDays->timezoneOf($userId)))->format('Y-m-d');
    }

    /**
     * The Budget Period containing $date.
     *
     * @param  string  $date  YYYY-MM-DD
     */
    public function periodContaining(string $userId, string $date): BudgetPeriodSpan
    {
        $period = $this->containing($userId, $date);

        return new BudgetPeriodSpan($period->start, $period->end);
    }

    /**
     * The Budget Period containing $date as the Budgeting Domain value, for
     * Budgeting's own progress arithmetic.
     *
     * @param  string  $date  YYYY-MM-DD
     */
    public function containing(string $userId, string $date): BudgetPeriod
    {
        return BudgetPeriods::containing($date, $this->entriesOf($userId), $this->startDays->currentDayOf($userId));
    }

    /**
     * $count consecutive Budget Periods, the last one containing $date, oldest first.
     *
     * @param  string  $date  YYYY-MM-DD
     * @return list<BudgetPeriodSpan>
     */
    public function periodsEndingWith(string $userId, string $date, int $count): array
    {
        $entries = $this->entriesOf($userId);
        $fallback = $this->startDays->currentDayOf($userId);
        $spans = [];

        for ($i = 0; $i < $count; $i++) {
            $period = BudgetPeriods::containing($date, $entries, $fallback);
            $spans[] = new BudgetPeriodSpan($period->start, $period->end);
            $date = (new DateTimeImmutable($period->start))->modify('-1 day')->format('Y-m-d');
        }

        return array_reverse($spans);
    }

    /**
     * @return list<StartDayEntry>
     */
    private function entriesOf(string $userId): array
    {
        return array_map(
            fn (array $entry): StartDayEntry => new StartDayEntry($entry['start_day'], $entry['applies_from']),
            $this->startDays->entriesOf($userId),
        );
    }
}

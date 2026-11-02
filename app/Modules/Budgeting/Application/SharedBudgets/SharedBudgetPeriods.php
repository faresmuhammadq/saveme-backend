<?php

namespace App\Modules\Budgeting\Application\SharedBudgets;

use App\Modules\Budgeting\Domain\BudgetPeriod;
use App\Modules\Budgeting\Domain\BudgetPeriods;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Budgeting\Domain\StartDayEntry;
use DateTimeImmutable;
use DateTimeZone;

/**
 * "Today" and the Budget Period of a Shared Budget, from its own timezone and
 * start day history, so every member reads the same dates (ADR-0027 rule 13).
 */
final class SharedBudgetPeriods
{
    public function __construct(private readonly SharedBudgetRepository $budgets) {}

    /**
     * @return string YYYY-MM-DD
     */
    public function today(SharedBudgetRecord $budget): string
    {
        return now()->toDateTimeImmutable()->setTimezone(new DateTimeZone($budget->timezone))->format('Y-m-d');
    }

    /**
     * @param  string  $date  YYYY-MM-DD
     */
    public function containing(SharedBudgetRecord $budget, string $date): BudgetPeriod
    {
        $entries = array_map(
            fn (array $entry): StartDayEntry => new StartDayEntry($entry['start_day'], $entry['applies_from']),
            $this->budgets->startDayHistory($budget->id),
        );

        return BudgetPeriods::containing($date, $entries, $budget->startDay);
    }

    /**
     * The day after the period containing today: when a start day change applies.
     *
     * @return string YYYY-MM-DD
     */
    public function nextPeriodStart(SharedBudgetRecord $budget): string
    {
        $period = $this->containing($budget, $this->today($budget));

        return (new DateTimeImmutable($period->end))->modify('+1 day')->format('Y-m-d');
    }
}

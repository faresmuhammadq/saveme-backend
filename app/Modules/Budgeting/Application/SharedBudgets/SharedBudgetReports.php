<?php

namespace App\Modules\Budgeting\Application\SharedBudgets;

use App\Modules\Budgeting\Domain\BudgetPeriod;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetProgress;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRules;
use App\Modules\Shared\Domain\Money\Money;
use DateTimeImmutable;

/**
 * Progress and reports of a Shared Budget (ADR-0027), computed on every read
 * from the budget's entries in one grouped query per period. Every member
 * reads the same figures: the period and "today" come from the budget, never
 * from the reader (ADR-0011 rule 7). A non-member is not-found.
 */
final class SharedBudgetReports
{
    /** The most periods a trend returns. */
    public const MAX_PERIODS = 24;

    public function __construct(
        private readonly SharedBudgetRepository $budgets,
        private readonly SharedBudgetAuthorizer $authorizer,
        private readonly SharedBudgetPeriods $periods,
    ) {}

    /**
     * Progress in the Budget Period containing `$date`, or today's.
     *
     * @param  ?string  $date  YYYY-MM-DD
     *
     * @throws SharedBudgetNotFound
     */
    public function progress(string $userId, string $budgetId, ?string $date): SharedBudgetProgress
    {
        $budget = $this->readable($userId, $budgetId);
        $today = $this->periods->today($budget);
        $period = $this->periods->containing($budget, $date ?? $today);

        return SharedBudgetProgress::of($budget, $period, $this->budgets->allLines($budgetId), $this->budgets->totals($budgetId, $period->start, $period->end), $today);
    }

    /**
     * Spending over any range: the total, by category and by author.
     *
     * @param  string  $from  YYYY-MM-DD
     * @param  string  $to  YYYY-MM-DD, inclusive
     * @return array{budget: SharedBudgetRecord, spent: Money, byCategory: array<string, Money>, byMember: array<string, Money>}
     *
     * @throws SharedBudgetNotFound
     */
    public function summary(string $userId, string $budgetId, string $from, string $to): array
    {
        $budget = $this->readable($userId, $budgetId);
        $spent = 0;
        $byCategory = [];
        $byMember = [];

        foreach ($this->budgets->totals($budgetId, $from, $to) as $total) {
            $spent += $total['amount_minor'];
            $byCategory[$total['category_id']] = ($byCategory[$total['category_id']] ?? 0) + $total['amount_minor'];
            $byMember[$total['created_by']] = ($byMember[$total['created_by']] ?? 0) + $total['amount_minor'];
        }

        ksort($byCategory, SORT_STRING);
        ksort($byMember, SORT_STRING);

        return [
            'budget' => $budget,
            'spent' => Money::of($spent, $budget->currency),
            'byCategory' => array_map(fn (int $minor): Money => Money::of($minor, $budget->currency), $byCategory),
            'byMember' => array_map(fn (int $minor): Money => Money::of($minor, $budget->currency), $byMember),
        ];
    }

    /**
     * One entry per Budget Period from the one containing `$from` through the
     * one containing `$to`, oldest first: what was spent and the overall limit
     * that period had.
     *
     * @return list<array{period: BudgetPeriod, spent: Money, limit: ?Money}>|null null when the range spans more than MAX_PERIODS
     *
     * @throws SharedBudgetNotFound
     */
    public function trend(string $userId, string $budgetId, string $from, string $to): ?array
    {
        $budget = $this->readable($userId, $budgetId);
        $period = $this->periods->containing($budget, $from);
        $rows = [];

        while ($period->start <= $to) {
            if (count($rows) === self::MAX_PERIODS) {
                return null;
            }

            $spent = array_sum(array_column($this->budgets->totals($budgetId, $period->start, $period->end), 'amount_minor'));
            $limit = SharedBudgetRules::limitOn($budget->limitHistory, $period->start);

            $rows[] = [
                'period' => $period,
                'spent' => Money::of($spent, $budget->currency),
                'limit' => $limit === null ? null : Money::of($limit, $budget->currency),
            ];

            $period = $this->periods->containing($budget, (new DateTimeImmutable($period->end))->modify('+1 day')->format('Y-m-d'));
        }

        return $rows;
    }

    private function readable(string $userId, string $budgetId): SharedBudgetRecord
    {
        $this->authorizer->member($userId, $budgetId);
        $budget = $this->budgets->find($budgetId) ?? throw new SharedBudgetNotFound;

        return $budget->isTombstoned() ? throw new SharedBudgetNotFound : $budget;
    }
}

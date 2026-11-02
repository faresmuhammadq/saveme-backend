<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets;

use App\Modules\Budgeting\Domain\BudgetPeriod;
use App\Modules\Shared\Domain\Money\Money;

/**
 * Shared Budget progress (ADR-0027): a derived read model of one budget in one
 * Budget Period, computed on every read from the budget's entries and never
 * stored. Every figure is an exact sum; no percentage is returned and no
 * amount is divided outside Money::allocate() (ADR-0003 rule 7). The
 * projection splits spent × days_in_period into days_elapsed parts and takes
 * the largest, for the current period only.
 *
 * Each line's limit and the overall limit are the ones the period had, from
 * the dated history, never today's.
 *
 * @phpstan-type Total array{created_by: string, category_id: string, amount_minor: int}
 * @phpstan-type Figure array{spent: Money, limit: ?Money, remaining: ?Money, over_limit: ?bool}
 */
final readonly class SharedBudgetProgress
{
    /**
     * @param  Figure  $overall
     * @param  list<array{line: SharedBudgetLineRecord, figure: Figure}>  $lines
     * @param  array<string, Money>  $byMember  author id => spent, ascending by id
     */
    private function __construct(
        public string $budgetId,
        public BudgetPeriod $period,
        public ?int $daysElapsed,
        public array $overall,
        public array $lines,
        public Money $unlisted,
        public array $byMember,
        public ?Money $projectedEnd,
    ) {}

    public function isCurrent(): bool
    {
        return $this->daysElapsed !== null;
    }

    /**
     * @param  list<SharedBudgetLineRecord>  $lines  every line, deleted ones included
     * @param  list<array{created_by: string, category_id: string, amount_minor: int}>  $totals  the period's entries, per author and category
     * @param  string  $today  YYYY-MM-DD in the budget's timezone
     */
    public static function of(SharedBudgetRecord $budget, BudgetPeriod $period, array $lines, array $totals, string $today): self
    {
        $currency = $budget->currency;
        $spentMinor = 0;
        $byCategory = [];
        $byMember = [];

        foreach ($totals as $total) {
            $spentMinor += $total['amount_minor'];
            $byCategory[$total['category_id']] = ($byCategory[$total['category_id']] ?? 0) + $total['amount_minor'];
            $byMember[$total['created_by']] = ($byMember[$total['created_by']] ?? 0) + $total['amount_minor'];
        }

        ksort($byMember, SORT_STRING);

        $listed = 0;
        $rows = [];

        foreach ($lines as $line) {
            $limit = SharedBudgetRules::limitOn($line->limitHistory, $period->start);
            $spent = $byCategory[$line->categoryId] ?? 0;

            // A line that was taken off the list stays only where it still means something: it had spending or a limit then.
            if ($line->isTombstoned() && $spent === 0 && $limit === null) {
                continue;
            }

            $listed += $spent;
            $rows[] = ['line' => $line, 'figure' => self::figure($spent, $limit, $currency)];
        }

        $daysElapsed = $period->daysElapsed($today);
        $spentMoney = Money::of($spentMinor, $currency);

        return new self(
            $budget->id,
            $period,
            $daysElapsed,
            self::figure($spentMinor, SharedBudgetRules::limitOn($budget->limitHistory, $period->start), $currency),
            $rows,
            Money::of($spentMinor - $listed, $currency),
            array_map(fn (int $minor): Money => Money::of($minor, $currency), $byMember),
            $daysElapsed === null ? null : self::projection($spentMoney, $period->daysInPeriod(), $daysElapsed),
        );
    }

    /**
     * @return Figure
     */
    private static function figure(int $spentMinor, ?int $limitMinor, string $currency): array
    {
        $spent = Money::of($spentMinor, $currency);

        if ($limitMinor === null) {
            return ['spent' => $spent, 'limit' => null, 'remaining' => null, 'over_limit' => null];
        }

        $limit = Money::of($limitMinor, $currency);

        return ['spent' => $spent, 'limit' => $limit, 'remaining' => $limit->minus($spent), 'over_limit' => $spentMinor > $limitMinor];
    }

    private static function projection(Money $spent, int $daysInPeriod, int $daysElapsed): ?Money
    {
        $total = $spent->amountMinor * $daysInPeriod;

        if ($total > Money::MAX_MINOR) {
            return null;
        }

        return Money::of($total, $spent->currency->code)->allocate($daysElapsed)[0];
    }
}

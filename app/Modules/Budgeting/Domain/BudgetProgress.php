<?php

namespace App\Modules\Budgeting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * Budget Progress (spec 015, FR-012 to FR-015): a derived read model of one
 * budget in one Budget Period, computed on every read and never stored.
 * Spending in other currencies is only named, never summed (P-03 rule 3).
 * The projection splits spent × days_in_period into days_elapsed parts with
 * Money::allocate() and takes the largest part; no amount is divided or
 * rounded here (ADR-0003 rule 7).
 */
final readonly class BudgetProgress
{
    /**
     * @param  list<string>  $otherCurrencies
     */
    private function __construct(
        public string $budgetId,
        public BudgetPeriod $period,
        public ?int $daysElapsed,
        public Money $limit,
        public Money $spent,
        public Money $remaining,
        public ?Money $projectedEnd,
        public bool $overLimit,
        public array $otherCurrencies,
    ) {}

    public function isCurrent(): bool
    {
        return $this->daysElapsed !== null;
    }

    /**
     * @param  list<SpendingLine>  $lines  the owner's spending in the period, per category and currency
     * @param  string  $today  YYYY-MM-DD in the owner's timezone
     */
    public static function of(BudgetRecord $budget, BudgetPeriod $period, array $lines, string $today): self
    {
        $limit = Money::of($budget->amountMinor, $budget->currency);
        $spentMinor = 0;
        $others = [];

        foreach ($lines as $line) {
            if ($budget->categoryId !== null && $line->categoryId !== $budget->categoryId) {
                continue;
            }

            if ($line->currency === $budget->currency) {
                $spentMinor += $line->amountMinor;
            } elseif ($line->amountMinor !== 0) {
                $others[$line->currency] = true;
            }
        }

        $others = array_map('strval', array_keys($others));
        sort($others, SORT_STRING);

        $spent = Money::of($spentMinor, $budget->currency);
        $daysElapsed = $period->daysElapsed($today);

        return new self(
            $budget->id,
            $period,
            $daysElapsed,
            $limit,
            $spent,
            $limit->minus($spent),
            $daysElapsed === null ? null : self::projection($spent, $period->daysInPeriod(), $daysElapsed),
            $spentMinor > $budget->amountMinor,
            $others,
        );
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

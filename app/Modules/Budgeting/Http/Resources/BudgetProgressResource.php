<?php

namespace App\Modules\Budgeting\Http\Resources;

use App\Modules\Budgeting\Domain\BudgetProgress;
use App\Modules\Shared\Domain\Money\Money;

/**
 * Budget Progress on the wire (spec 015, section 5): a derived read model,
 * Money as `{amount_minor, currency}`.
 */
final class BudgetProgressResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(BudgetProgress $progress): array
    {
        return [
            'budget_id' => $progress->budgetId,
            'period' => [
                'start' => $progress->period->start,
                'end' => $progress->period->end,
                'days_in_period' => $progress->period->daysInPeriod(),
                'days_elapsed' => $progress->daysElapsed,
            ],
            'is_current' => $progress->isCurrent(),
            'limit' => self::money($progress->limit),
            'spent' => self::money($progress->spent),
            'remaining' => self::money($progress->remaining),
            'projected_end' => $progress->projectedEnd === null ? null : self::money($progress->projectedEnd),
            'over_limit' => $progress->overLimit,
            'other_currencies' => $progress->otherCurrencies,
        ];
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    private static function money(Money $money): array
    {
        return ['amount_minor' => $money->amountMinor, 'currency' => $money->currency->code];
    }
}

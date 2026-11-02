<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Resources;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetProgress;
use App\Modules\Shared\Domain\Money\Money;

/**
 * Shared Budget progress on the wire: a derived read model, Money as
 * `{amount_minor, currency}`. A member's name is null for someone who has
 * left, which clients show as "Former member".
 */
final class SharedBudgetProgressResource
{
    /**
     * @param  array<string, ?string>  $names  user id => display name
     * @return array<string, mixed>
     */
    public static function toArray(SharedBudgetProgress $progress, array $names): array
    {
        return [
            'shared_budget_id' => $progress->budgetId,
            'period' => [
                'start' => $progress->period->start,
                'end' => $progress->period->end,
                'days_in_period' => $progress->period->daysInPeriod(),
                'days_elapsed' => $progress->daysElapsed,
            ],
            'is_current' => $progress->isCurrent(),
            ...self::figure($progress->overall),
            'projected_end' => $progress->projectedEnd === null ? null : self::money($progress->projectedEnd),
            'lines' => array_map(fn (array $row): array => [
                'line_id' => $row['line']->id,
                'category_id' => $row['line']->categoryId,
                ...self::figure($row['figure']),
            ], $progress->lines),
            'unlisted_spent' => self::money($progress->unlisted),
            'members' => array_map(fn (string $userId, Money $spent): array => [
                'user_id' => $userId,
                'display_name' => $names[$userId] ?? null,
                'spent' => self::money($spent),
            ], array_keys($progress->byMember), array_values($progress->byMember)),
        ];
    }

    /**
     * @param  array{spent: Money, limit: ?Money, remaining: ?Money, over_limit: ?bool}  $figure
     * @return array<string, mixed>
     */
    private static function figure(array $figure): array
    {
        return [
            'limit' => $figure['limit'] === null ? null : self::money($figure['limit']),
            'spent' => self::money($figure['spent']),
            'remaining' => $figure['remaining'] === null ? null : self::money($figure['remaining']),
            'over_limit' => $figure['over_limit'],
        ];
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    public static function money(Money $money): array
    {
        return ['amount_minor' => $money->amountMinor, 'currency' => $money->currency->code];
    }
}

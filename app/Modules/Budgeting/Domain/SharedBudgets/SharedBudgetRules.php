<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets;

use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetStateInvalid;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;

/**
 * The state rules of a Shared Budget write (ADR-0027): the currency never
 * changes, and neither does a line's category.
 */
final class SharedBudgetRules
{
    /**
     * @param  array{name: string, currency: string, start_day: int, limit_minor: ?int}  $values
     *
     * @throws SharedBudgetStateInvalid
     */
    public static function assertCurrencyUnchanged(SharedBudgetRecord $current, array $values): void
    {
        if ($values['currency'] !== $current->currency) {
            throw new SharedBudgetStateInvalid([new FieldError('/currency', ErrorCode::ImmutableField)]);
        }
    }

    /**
     * @throws SharedBudgetStateInvalid
     */
    public static function assertCategoryUnchanged(SharedBudgetLineRecord $current, string $categoryId): void
    {
        if ($categoryId !== $current->categoryId) {
            throw new SharedBudgetStateInvalid([new FieldError('/category_id', ErrorCode::ImmutableField)]);
        }
    }

    /**
     * The limit a period reads: the latest one applying on or before its
     * start, or null when none does.
     *
     * @param  list<array{applies_from: string, limit_minor: ?int}>  $history
     */
    public static function limitOn(array $history, string $periodStart): ?int
    {
        $limit = null;
        $from = '';

        foreach ($history as $entry) {
            if ($entry['applies_from'] <= $periodStart && $entry['applies_from'] >= $from) {
                $from = $entry['applies_from'];
                $limit = $entry['limit_minor'];
            }
        }

        return $limit;
    }
}

<?php

namespace App\Modules\Budgeting\Domain;

use App\Modules\Budgeting\Domain\Refusals\BudgetStateInvalid;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;

/**
 * The state rules of a budget write (spec 015, FR-002, FR-006): the category,
 * the currency and the Budget Period type never change once created; only the
 * limit does. Violations are collected, not thrown one at a time.
 */
final class BudgetRules
{
    /**
     * @param  array{category_id: ?string, period_type: string, amount_minor: int, currency: string}  $values
     *
     * @throws BudgetStateInvalid
     */
    public static function assertImmutablesUnchanged(BudgetRecord $current, array $values): void
    {
        $errors = [];

        if ($values['category_id'] !== $current->categoryId) {
            $errors[] = new FieldError('/category_id', ErrorCode::ImmutableField);
        }

        if ($values['currency'] !== $current->currency) {
            $errors[] = new FieldError('/currency', ErrorCode::ImmutableField);
        }

        if ($values['period_type'] !== $current->periodType->value) {
            $errors[] = new FieldError('/period_type', ErrorCode::ImmutableField);
        }

        if ($errors !== []) {
            throw new BudgetStateInvalid($errors);
        }
    }

    /**
     * Whether a write changes nothing.
     *
     * @param  array{category_id: ?string, period_type: string, amount_minor: int, currency: string}  $values
     */
    public static function isIdentical(BudgetRecord $current, array $values): bool
    {
        return $values === $current->writableValues();
    }

    /**
     * @return list<string>
     */
    public static function periodTypes(): array
    {
        return array_map(fn (PeriodType $type): string => $type->value, PeriodType::cases());
    }
}

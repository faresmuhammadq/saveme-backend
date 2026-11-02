<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Requests;

use App\Http\Api\Requests\ApiRequest;
use App\Modules\Budgeting\Http\BudgetId;

/**
 * Base for the Shared Budget write bodies. The path identifier is checked
 * before the body (INVALID_ID precedes VALIDATION_FAILED).
 */
abstract class SharedBudgetRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        BudgetId::assert($this->route('id'));
    }

    public function budgetId(): string
    {
        return BudgetId::assert($this->route('id'));
    }

    public function version(): int
    {
        return $this->integer('version');
    }

    protected function nullableInteger(string $member): ?int
    {
        return $this->input($member) === null ? null : $this->integer($member);
    }
}

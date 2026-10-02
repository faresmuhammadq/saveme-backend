<?php

namespace App\Modules\Budgeting\Http\Requests;

use App\Http\Api\Requests\ApiRequest;
use App\Modules\Budgeting\Http\BudgetId;

/**
 * Base for the budget write bodies. The path identifier is checked before
 * the body (INVALID_ID precedes VALIDATION_FAILED, spec 015 section 6).
 */
abstract class BudgetRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        BudgetId::assert($this->route('id'));
    }

    public function budgetId(): string
    {
        return BudgetId::assert($this->route('id'));
    }
}

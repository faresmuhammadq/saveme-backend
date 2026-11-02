<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Requests;

use App\Modules\Budgeting\Http\BudgetId;

/**
 * Removing a line carries nothing but its last-seen version.
 */
final class DeleteSharedBudgetLineRequest extends SharedBudgetRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        BudgetId::assert($this->route('lineId'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function lineId(): string
    {
        return BudgetId::assert($this->route('lineId'));
    }
}

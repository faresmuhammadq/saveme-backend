<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Requests;

use App\Http\Api\Rules\MinorUnits;
use App\Modules\Budgeting\Http\BudgetId;

/**
 * A line's category and optional limit plus the last-seen version; omitted or 0 means create.
 */
final class SaveSharedBudgetLineRequest extends SharedBudgetRequest
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
            'category_id' => ['bail', 'required', 'string', 'uuid'],
            'limit_minor' => ['bail', 'present', 'nullable', new MinorUnits, 'integer', 'min:1', 'max:9007199254740991'],
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function lineId(): string
    {
        return BudgetId::assert($this->route('lineId'));
    }

    /**
     * @return array{category_id: string, limit_minor: ?int}
     */
    public function values(): array
    {
        return [
            'category_id' => $this->string('category_id')->toString(),
            'limit_minor' => $this->nullableInteger('limit_minor'),
        ];
    }
}

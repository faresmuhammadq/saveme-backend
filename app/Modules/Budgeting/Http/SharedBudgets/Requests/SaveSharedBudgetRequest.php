<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Requests;

use App\Http\Api\Rules\IsoCurrency;
use App\Http\Api\Rules\MinorUnits;

/**
 * The whole writable budget plus the last-seen version; omitted or 0 means create.
 */
final class SaveSharedBudgetRequest extends SharedBudgetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:100'],
            'currency' => ['bail', 'required', 'string', new IsoCurrency],
            'start_day' => ['bail', 'required', 'integer', 'min:1', 'max:28'],
            'limit_minor' => ['bail', 'present', 'nullable', new MinorUnits, 'integer', 'min:1', 'max:9007199254740991'],
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array{name: string, currency: string, start_day: int, limit_minor: ?int}
     */
    public function values(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'currency' => $this->string('currency')->toString(),
            'start_day' => $this->integer('start_day'),
            'limit_minor' => $this->nullableInteger('limit_minor'),
        ];
    }
}

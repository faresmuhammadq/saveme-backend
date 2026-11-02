<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Requests;

use App\Http\Api\Rules\MinorUnits;

/**
 * Any of the changeable members plus the required version. The currency is
 * immutable, so it is an unknown member.
 */
final class PatchSharedBudgetRequest extends SharedBudgetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'sometimes', 'required', 'string', 'max:100'],
            'start_day' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:28'],
            'limit_minor' => ['bail', 'sometimes', 'present', 'nullable', new MinorUnits, 'integer', 'min:1', 'max:9007199254740991'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function partial(): array
    {
        $partial = [];

        if ($this->has('name')) {
            $partial['name'] = $this->string('name')->toString();
        }

        if ($this->has('start_day')) {
            $partial['start_day'] = $this->integer('start_day');
        }

        if ($this->exists('limit_minor')) {
            $partial['limit_minor'] = $this->nullableInteger('limit_minor');
        }

        return $partial;
    }
}

<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Requests;

/**
 * Archive and delete carry nothing but the last-seen version, in a JSON body.
 */
final class SharedBudgetVersionRequest extends SharedBudgetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}

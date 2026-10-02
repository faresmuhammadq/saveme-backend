<?php

namespace App\Modules\Budgeting\Http\Requests;

use App\Http\Api\Rules\MinorUnits;

/**
 * The limit plus the required version (spec 015, FR-006). Everything else is
 * immutable or server-owned, so it is an unknown member.
 */
final class PatchBudgetRequest extends BudgetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_minor' => ['bail', 'required', new MinorUnits, 'integer', 'min:1', 'max:9007199254740991'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function amountMinor(): int
    {
        return $this->integer('amount_minor');
    }

    public function version(): int
    {
        return $this->integer('version');
    }
}

<?php

namespace App\Modules\Budgeting\Http\Requests;

use App\Http\Api\Rules\IsoCurrency;
use App\Http\Api\Rules\MinorUnits;
use App\Modules\Budgeting\Domain\BudgetRules;
use Illuminate\Validation\Rule;

/**
 * The whole writable budget plus the last-seen version (spec 015, FR-002).
 * Every member but `version` is required; `category_id` may be null.
 */
final class SaveBudgetRequest extends BudgetRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['present', 'nullable', 'string', 'uuid'],
            'period_type' => ['bail', 'required', 'string', Rule::in(BudgetRules::periodTypes())],
            'amount_minor' => ['bail', 'required', new MinorUnits, 'integer', 'min:1', 'max:9007199254740991'],
            'currency' => ['bail', 'required', 'string', new IsoCurrency],
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array{category_id: ?string, period_type: string, amount_minor: int, currency: string}
     */
    public function values(): array
    {
        return [
            'category_id' => $this->input('category_id') === null ? null : $this->string('category_id')->toString(),
            'period_type' => $this->string('period_type')->toString(),
            'amount_minor' => $this->integer('amount_minor'),
            'currency' => $this->string('currency')->toString(),
        ];
    }

    /**
     * Omitted or 0 means create.
     */
    public function version(): int
    {
        return $this->integer('version');
    }
}

<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Api\Rules\IsoCurrency;

/**
 * The whole writable profile plus the last-seen version (spec 008, FR-003).
 */
final class SaveProfileRequest extends IdentityRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['present', 'nullable', 'string', 'max:100'],
            'locale' => ['required', 'string', 'in:ar,en'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'default_currency' => ['required', 'string', new IsoCurrency],
            'budget_period_start_day' => ['required', 'integer', 'between:1,28'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{display_name: ?string, locale: string, timezone: string, default_currency: string, budget_period_start_day: int}
     */
    public function values(): array
    {
        return [
            'display_name' => $this->input('display_name') === null ? null : $this->string('display_name')->toString(),
            'locale' => $this->string('locale')->toString(),
            'timezone' => $this->string('timezone')->toString(),
            'default_currency' => $this->string('default_currency')->toString(),
            'budget_period_start_day' => $this->integer('budget_period_start_day'),
        ];
    }
}

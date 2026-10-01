<?php

namespace App\Modules\Wallets\Http\Requests;

use App\Http\Api\Rules\IsoCurrency;
use App\Http\Api\Rules\MinorUnits;
use App\Http\Api\Rules\NotBlank;

/**
 * The whole writable wallet plus the last-seen version (spec 010, FR-001,
 * FR-003). A name that is blank or longer than 100 is OUT_OF_RANGE, and the
 * NotBlank rule runs first because the pipeline has already turned a blank
 * string into null.
 */
final class SaveWalletRequest extends WalletRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['bail', 'required', 'string', 'in:cash,checking,savings,credit_card,loan,investment'],
            'name' => ['bail', 'present', new NotBlank, 'string', 'between:1,100'],
            'currency' => ['bail', 'required', 'string', new IsoCurrency],
            'opening_balance_minor' => ['bail', 'required', new MinorUnits],
            'icon' => ['present', 'nullable', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'color' => ['present', 'nullable', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'version' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array{type: string, name: string, currency: string, opening_balance_minor: int, icon: ?string, color: ?string}
     */
    public function values(): array
    {
        return [
            'type' => $this->string('type')->toString(),
            'name' => $this->string('name')->toString(),
            'currency' => $this->string('currency')->toString(),
            'opening_balance_minor' => $this->integer('opening_balance_minor'),
            'icon' => $this->input('icon') === null ? null : $this->string('icon')->toString(),
            'color' => $this->input('color') === null ? null : $this->string('color')->toString(),
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

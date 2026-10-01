<?php

namespace App\Modules\Wallets\Http\Requests;

use App\Http\Api\Problems\ApiRefusal;
use App\Http\Api\Rules\IsoCurrency;
use App\Http\Api\Rules\MinorUnits;
use App\Http\Api\Rules\NotBlank;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;

/**
 * Any non-empty subset of the editable members plus the required version
 * (spec 010, FR-003). The type is not editable, so it is an unknown member.
 * A body carrying only `version` is REQUIRED at the body root.
 */
final class PatchWalletRequest extends WalletRequest
{
    private const EDITABLE = ['name', 'currency', 'opening_balance_minor', 'icon', 'color'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'bail', new NotBlank, 'string', 'between:1,100'],
            'currency' => ['sometimes', 'bail', 'required', 'string', new IsoCurrency],
            'opening_balance_minor' => ['sometimes', 'bail', 'required', new MinorUnits],
            'icon' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9_]{1,40}$/'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function passedValidation(): void
    {
        parent::passedValidation();

        if (array_intersect(self::EDITABLE, array_keys($this->json()->all())) === []) {
            throw new ApiRefusal(ErrorCode::ValidationFailed, fieldErrors: [new FieldError('', ErrorCode::Required)]);
        }
    }

    /**
     * The editable members that were sent.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return array_intersect_key($this->json()->all(), array_flip(self::EDITABLE));
    }

    public function version(): int
    {
        return $this->integer('version');
    }
}

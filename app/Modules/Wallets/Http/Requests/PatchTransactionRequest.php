<?php

namespace App\Modules\Wallets\Http\Requests;

use App\Http\Api\Problems\ApiRefusal;
use App\Http\Api\Rules\CalendarDate;
use App\Http\Api\Rules\MinorUnits;
use App\Http\Api\Rules\MustBeNull;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;

/**
 * Two shapes, both carrying the last-seen version (spec 012, `TransactionPatch`
 * and `TransactionRestore`). A body with a `deleted_at` member is a restore:
 * exactly `{deleted_at: null, version}`, anything else is unknown or, for a
 * non-null `deleted_at`, INVALID_TYPE. Any other body is an edit: a non-empty
 * subset of the editable members; `kind`, `currency` and `counter_wallet_id`
 * are unknown. A body carrying only `version` is REQUIRED at the root.
 */
final class PatchTransactionRequest extends TransactionRequest
{
    private const EDITABLE = ['date', 'amount_minor', 'category_id', 'description', 'memo', 'counterparty', 'cleared', 'shared_budget_id'];

    public function isRestore(): bool
    {
        return array_key_exists('deleted_at', $this->json()->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isRestore()) {
            return [
                'deleted_at' => ['present', new MustBeNull],
                'version' => ['required', 'integer', 'min:1'],
            ];
        }

        return [
            'date' => ['sometimes', 'bail', 'required', new CalendarDate, 'after_or_equal:1900-01-01', 'before_or_equal:2999-12-31'],
            'amount_minor' => ['sometimes', 'bail', 'required', new MinorUnits, 'integer', 'min:1', 'max:9007199254740991'],
            'category_id' => ['sometimes', 'bail', 'required', 'string', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:100'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'counterparty' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cleared' => ['sometimes', 'bail', 'required', 'boolean:strict'],
            'shared_budget_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function passedValidation(): void
    {
        parent::passedValidation();

        if (! $this->isRestore() && array_intersect(self::EDITABLE, array_keys($this->json()->all())) === []) {
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

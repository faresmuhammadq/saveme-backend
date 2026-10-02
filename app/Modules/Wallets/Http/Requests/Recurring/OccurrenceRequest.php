<?php

namespace App\Modules\Wallets\Http\Requests\Recurring;

use App\Http\Api\Requests\ApiRequest;
use App\Modules\Wallets\Http\Requests\WalletId;

/**
 * Base for the occurrence write body. The path identifiers are checked
 * before the body (INVALID_ID precedes VALIDATION_FAILED).
 */
abstract class OccurrenceRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        WalletId::assert($this->route('id'));
        OccurrenceId::assert($this->route('oid'));
    }

    public function walletId(): string
    {
        return WalletId::assert($this->route('id'));
    }

    public function occurrenceId(): string
    {
        return OccurrenceId::assert($this->route('oid'));
    }
}

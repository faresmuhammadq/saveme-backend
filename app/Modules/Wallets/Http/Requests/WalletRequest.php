<?php

namespace App\Modules\Wallets\Http\Requests;

use App\Http\Api\Requests\ApiRequest;

/**
 * Base for Wallets' write bodies. The path identifier is checked before the
 * body (INVALID_ID precedes VALIDATION_FAILED, spec 010 section 6).
 */
abstract class WalletRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        WalletId::assert($this->route('id'));
    }

    public function walletId(): string
    {
        return WalletId::assert($this->route('id'));
    }
}

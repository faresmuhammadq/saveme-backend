<?php

namespace App\Modules\Wallets\Http\Requests;

use App\Http\Api\Requests\ApiRequest;

/**
 * Base for the transaction write bodies. The path identifiers are checked
 * before the body (INVALID_ID precedes VALIDATION_FAILED, spec 012 section 6).
 */
abstract class TransactionRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        WalletId::assert($this->route('id'));
        TransactionId::assert($this->route('tid'));
    }

    public function walletId(): string
    {
        return WalletId::assert($this->route('id'));
    }

    public function transactionId(): string
    {
        return TransactionId::assert($this->route('tid'));
    }
}

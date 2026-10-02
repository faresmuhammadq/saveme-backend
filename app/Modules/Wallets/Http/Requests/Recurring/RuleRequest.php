<?php

namespace App\Modules\Wallets\Http\Requests\Recurring;

use App\Http\Api\Requests\ApiRequest;
use App\Modules\Wallets\Http\Requests\WalletId;

/**
 * Base for the recurring rule write bodies. The path identifiers are checked
 * before the body (INVALID_ID precedes VALIDATION_FAILED, spec 014 section 6).
 */
abstract class RuleRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        WalletId::assert($this->route('id'));
        RuleId::assert($this->route('rid'));
    }

    public function walletId(): string
    {
        return WalletId::assert($this->route('id'));
    }

    public function ruleId(): string
    {
        return RuleId::assert($this->route('rid'));
    }
}

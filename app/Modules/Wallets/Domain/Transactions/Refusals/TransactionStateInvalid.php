<?php

namespace App\Modules\Wallets\Domain\Transactions\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The write breaks a state rule (spec 012, section 5): one 422
 * VALIDATION_FAILED carrying every field error (IMMUTABLE_FIELD,
 * CURRENCY_MISMATCH, INVALID_ENUM_VALUE, REQUIRED), one entry each.
 */
final class TransactionStateInvalid extends Refusal
{
    /**
     * @param  non-empty-list<FieldError>  $fieldErrors
     */
    public function __construct(array $fieldErrors)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: $fieldErrors);
    }
}

<?php

namespace App\Modules\Wallets\Domain\Recurring\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A rule or occurrence write breaks a state rule (spec 014, section 5): one
 * 422 VALIDATION_FAILED carrying every field error, one entry each.
 */
final class RecurringStateInvalid extends Refusal
{
    /**
     * @param  non-empty-list<FieldError>  $fieldErrors
     */
    public function __construct(array $fieldErrors)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: $fieldErrors);
    }
}

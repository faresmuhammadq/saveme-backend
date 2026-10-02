<?php

namespace App\Modules\Budgeting\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A budget write the stored state refuses: an immutable member changed, or
 * the category is not one the writer may budget (spec 015, section 6).
 */
final class BudgetStateInvalid extends Refusal
{
    /**
     * @param  list<FieldError>  $fieldErrors
     */
    public function __construct(array $fieldErrors)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: $fieldErrors);
    }
}

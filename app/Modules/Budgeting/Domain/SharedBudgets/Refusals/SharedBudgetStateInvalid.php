<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A Shared Budget write the stored state refuses.
 */
final class SharedBudgetStateInvalid extends Refusal
{
    /**
     * @param  list<FieldError>  $fieldErrors
     */
    public function __construct(array $fieldErrors)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: $fieldErrors);
    }
}

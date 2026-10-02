<?php

namespace App\Modules\Budgeting\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * Not the caller's budget, another kind of record, or nothing at all (P-06
 * rule 3, P-08 rule 3): not-found, never a hint it exists.
 */
final class BudgetNotFound extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::NotFound);
    }
}

<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * Not a member of the budget, another kind of record, or nothing at all
 * (ADR-0027 rule 2): not-found, never a hint it exists.
 */
final class SharedBudgetNotFound extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::NotFound);
    }
}

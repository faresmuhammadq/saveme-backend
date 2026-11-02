<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A member whose role does not permit the action (ADR-0027 rules 2 and 3).
 */
final class SharedBudgetActionForbidden extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::Forbidden);
    }
}

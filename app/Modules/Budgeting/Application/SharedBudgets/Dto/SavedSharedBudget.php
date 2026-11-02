<?php

namespace App\Modules\Budgeting\Application\SharedBudgets\Dto;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;

/**
 * A saved budget, and whether this write created it.
 */
final readonly class SavedSharedBudget
{
    public function __construct(public SharedBudgetRecord $budget, public bool $created) {}
}

<?php

namespace App\Modules\Budgeting\Application\Dto;

use App\Modules\Budgeting\Domain\BudgetRecord;

/**
 * The outcome of a whole save: the budget and whether this call created it.
 */
final readonly class SavedBudget
{
    public function __construct(
        public BudgetRecord $budget,
        public bool $created,
    ) {}
}

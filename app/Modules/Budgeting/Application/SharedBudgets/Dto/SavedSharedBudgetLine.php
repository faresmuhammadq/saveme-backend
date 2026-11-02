<?php

namespace App\Modules\Budgeting\Application\SharedBudgets\Dto;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetLineRecord;

/**
 * A saved line, and whether this write created it.
 */
final readonly class SavedSharedBudgetLine
{
    public function __construct(public SharedBudgetLineRecord $line, public bool $created) {}
}

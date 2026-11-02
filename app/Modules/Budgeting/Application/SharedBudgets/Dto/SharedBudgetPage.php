<?php

namespace App\Modules\Budgeting\Application\SharedBudgets\Dto;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;

/**
 * One page of the caller's shared budgets.
 */
final readonly class SharedBudgetPage
{
    /**
     * @param  list<SharedBudgetRecord>  $items
     * @param  array{value: string, id: string}|null  $next
     */
    public function __construct(public array $items, public ?array $next) {}
}

<?php

namespace App\Modules\Budgeting\Application\Dto;

use App\Modules\Budgeting\Domain\BudgetRecord;

/**
 * One page of budgets and the keyset position of the next, or null at the end.
 */
final readonly class BudgetPage
{
    /**
     * @param  list<BudgetRecord>  $items
     * @param  array{value: string, id: string}|null  $next
     */
    public function __construct(
        public array $items,
        public ?array $next,
    ) {}
}

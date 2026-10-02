<?php

namespace App\Modules\Budgeting\Application\Dto;

use App\Modules\Budgeting\Domain\BudgetProgress;

/**
 * One page of Budget Progress, every entry in the same Budget Period. The
 * next position carries that period's start so a later page keeps it.
 */
final readonly class ProgressPage
{
    /**
     * @param  list<BudgetProgress>  $items
     * @param  array{value: string, id: string, period_start: string}|null  $next
     */
    public function __construct(
        public array $items,
        public ?array $next,
    ) {}
}

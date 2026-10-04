<?php

namespace App\Modules\Budgeting\Application\Dto;

/**
 * A Budget Period as two inclusive calendar dates, for other modules (spec
 * 019): Reporting reads periods through Budgeting's Application layer and
 * imports nothing from its Domain.
 */
final readonly class BudgetPeriodSpan
{
    /**
     * @param  string  $from  YYYY-MM-DD
     * @param  string  $to  YYYY-MM-DD, inclusive
     */
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}

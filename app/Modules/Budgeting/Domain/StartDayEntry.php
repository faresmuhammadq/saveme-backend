<?php

namespace App\Modules\Budgeting\Domain;

use InvalidArgumentException;

/**
 * One entry of a person's budget start day history: the day of the month
 * Budget Periods begin on, from a date on (null for the first entry).
 */
final readonly class StartDayEntry
{
    /**
     * @param  ?string  $appliesFrom  YYYY-MM-DD
     */
    public function __construct(
        public int $startDay,
        public ?string $appliesFrom,
    ) {
        if ($startDay < 1 || $startDay > 28) {
            throw new InvalidArgumentException('A budget start day is 1 to 28.');
        }
    }
}

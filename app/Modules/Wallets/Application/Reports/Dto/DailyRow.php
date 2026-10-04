<?php

namespace App\Modules\Wallets\Application\Reports\Dto;

/**
 * The sum of the wallet legs of one kind (income or expense) dated one day.
 */
final readonly class DailyRow
{
    /**
     * @param  string  $date  YYYY-MM-DD
     */
    public function __construct(
        public string $date,
        public string $kind,
        public int $amountMinor,
    ) {}
}

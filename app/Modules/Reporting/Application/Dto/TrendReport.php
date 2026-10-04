<?php

namespace App\Modules\Reporting\Application\Dto;

use App\Modules\Reporting\Domain\TrendSeries;

/**
 * A trend Report (spec 019): consecutive Budget Periods, oldest first.
 */
final readonly class TrendReport
{
    /**
     * @param  list<ExcludedWallet>|null  $excluded  null for a wallet report
     */
    public function __construct(
        public ?string $walletId,
        public string $currency,
        public TrendSeries $series,
        public ?array $excluded,
    ) {}
}

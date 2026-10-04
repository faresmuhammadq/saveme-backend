<?php

namespace App\Modules\Reporting\Application\Dto;

use App\Modules\Reporting\Domain\CategoryBreakdown;
use App\Modules\Reporting\Domain\ReportRange;

/**
 * A by-category Report (spec 019).
 */
final readonly class CategoryReport
{
    /**
     * @param  list<ExcludedWallet>|null  $excluded  null for a wallet report
     */
    public function __construct(
        public ?string $walletId,
        public ReportRange $range,
        public string $currency,
        public CategoryBreakdown $breakdown,
        public ?array $excluded,
    ) {}
}

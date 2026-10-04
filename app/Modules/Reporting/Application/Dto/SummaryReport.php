<?php

namespace App\Modules\Reporting\Application\Dto;

use App\Modules\Reporting\Domain\PlannedSummary;
use App\Modules\Reporting\Domain\ReportRange;
use App\Modules\Reporting\Domain\SummaryFigures;

/**
 * A summary Report (spec 019): one wallet's (`walletId` set, `excluded` null)
 * or the caller's covered wallets' (`walletId` null).
 */
final readonly class SummaryReport
{
    /**
     * @param  list<ExcludedWallet>|null  $excluded  null for a wallet report
     */
    public function __construct(
        public ?string $walletId,
        public ReportRange $range,
        public string $currency,
        public SummaryFigures $figures,
        public ?PlannedSummary $planned,
        public ?array $excluded,
    ) {}
}

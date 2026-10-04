<?php

namespace App\Modules\Reporting\Application\Dto;

use App\Modules\Reporting\Domain\ReportRange;

/**
 * The parsed query of a report (spec 019, FR-019). `range` is set only for an
 * explicit `from`/`to`; otherwise the range is the caller's Budget Period
 * containing `date` (today when absent). `months` is the trend's length.
 */
final readonly class ReportRequest
{
    public const DEFAULT_MONTHS = 6;

    /**
     * @param  ?string  $date  YYYY-MM-DD
     * @param  ?string  $currency  uppercase ISO 4217; null means the profile default
     */
    public function __construct(
        public ?string $date = null,
        public ?ReportRange $range = null,
        public int $months = self::DEFAULT_MONTHS,
        public bool $includePlanned = false,
        public ?string $currency = null,
    ) {}
}

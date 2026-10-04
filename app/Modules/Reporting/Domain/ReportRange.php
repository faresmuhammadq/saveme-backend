<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Reporting\Domain\Refusals\InvalidReportRange;
use DateTimeImmutable;

/**
 * The inclusive span of calendar dates a report covers (glossary: Report
 * Range). Either a Budget Period, which is served as computed (a transitional
 * period may exceed a month, A-27), or an explicit span of at most 366 days
 * within 1900-01-01 to 2999-12-31. Never an instant, never stored.
 */
final readonly class ReportRange
{
    private const FIRST = '1900-01-01';

    private const LAST = '2999-12-31';

    private const MAX_DAYS = 366;

    /**
     * @param  string  $from  YYYY-MM-DD
     * @param  string  $to  YYYY-MM-DD, inclusive
     */
    private function __construct(
        public string $from,
        public string $to,
    ) {}

    /**
     * @param  string  $from  YYYY-MM-DD
     * @param  string  $to  YYYY-MM-DD
     *
     * @throws InvalidReportRange
     */
    public static function explicit(string $from, string $to): self
    {
        if ($from < self::FIRST || $to > self::LAST || $from > $to) {
            throw new InvalidReportRange;
        }

        $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;

        if ($days > self::MAX_DAYS) {
            throw new InvalidReportRange;
        }

        return new self($from, $to);
    }

    /**
     * @param  string  $from  YYYY-MM-DD
     * @param  string  $to  YYYY-MM-DD
     */
    public static function ofBudgetPeriod(string $from, string $to): self
    {
        return new self($from, $to);
    }
}

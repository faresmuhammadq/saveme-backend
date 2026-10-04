<?php

namespace App\Modules\Reporting\Domain;

/**
 * One Budget Period of a trend: two inclusive calendar dates, labelled by
 * the start date (the key of the period in every series).
 */
final readonly class TrendBucket
{
    /**
     * @param  string  $from  YYYY-MM-DD
     * @param  string  $to  YYYY-MM-DD, inclusive
     */
    public function __construct(
        public string $from,
        public string $to,
    ) {}

    public function label(): string
    {
        return $this->from;
    }

    public function contains(string $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }
}

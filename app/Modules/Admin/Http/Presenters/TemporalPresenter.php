<?php

namespace App\Modules\Admin\Http\Presenters;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Calendar dates, instants and relative time for the back office (spec 006,
 * FR-022, FR-023; constitution P-12).
 *
 * A calendar date is a label, never a moment: it is shown as stored. An
 * instant is stored in UTC and shown in the operator's timezone with the
 * zone always visible.
 */
class TemporalPresenter
{
    public const RELATIVE_THRESHOLD_DAYS = 7;

    public function calendarDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException("A calendar date is YYYY-MM-DD, [{$date}] given.");
        }

        return $date;
    }

    public function instant(string $utc, string $timezone, string $locale): string
    {
        $moment = $this->localized(CarbonImmutable::parse($utc, 'UTC')->setTimezone($timezone), $locale);

        return $locale === 'ar'
            ? $moment->isoFormat('D MMMM YYYY، HH:mm z')
            : $moment->isoFormat('D MMM YYYY, HH:mm z');
    }

    public function relative(string $utc, CarbonInterface $now, string $timezone, string $locale, int $thresholdDays = self::RELATIVE_THRESHOLD_DAYS): string
    {
        $moment = CarbonImmutable::parse($utc, 'UTC');

        if ($moment->diffInDays($now, absolute: true) >= $thresholdDays) {
            return $this->instant($utc, $timezone, $locale);
        }

        return $this->localized($moment, $locale)->diffForHumans($now, ['syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW]);
    }

    private function localized(CarbonImmutable $moment, string $locale): CarbonImmutable
    {
        $localized = $moment->locale($locale);

        return $localized instanceof CarbonImmutable ? $localized : $moment;
    }

    /**
     * The ISO-8601 value for a <time datetime> attribute.
     */
    public function machineInstant(string $utc): string
    {
        return CarbonImmutable::parse($utc, 'UTC')->toIso8601ZuluString();
    }
}

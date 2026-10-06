<?php

namespace App\Modules\Notifications\Domain\Preferences;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A person's quiet hours: wall-clock minutes since midnight, evaluated in the
 * profile timezone at send time (P-12 rule 5, spec 023 FR-017). The start is
 * inclusive and the end exclusive; an end before the start wraps midnight.
 * Nothing here is stored as an instant, so a daylight-saving shift or a
 * timezone change rewrites nothing.
 */
final readonly class QuietHours
{
    public function __construct(
        public bool $enabled,
        public int $startMinutes,
        public int $endMinutes,
    ) {}

    public function contains(DateTimeImmutable $utc, string $timezone): bool
    {
        if (! $this->enabled || $this->startMinutes === $this->endMinutes) {
            return false;
        }

        $local = $utc->setTimezone(new DateTimeZone($timezone));
        $minute = ((int) $local->format('G')) * 60 + (int) $local->format('i');

        if ($this->startMinutes < $this->endMinutes) {
            return $minute >= $this->startMinutes && $minute < $this->endMinutes;
        }

        return $minute >= $this->startMinutes || $minute < $this->endMinutes;
    }

    /**
     * The next instant at which the wall clock reads the end of quiet hours.
     * A local end time that does not exist that day (a forward shift) moves
     * forward to the first valid instant.
     */
    public function endAfter(DateTimeImmutable $utc, string $timezone): DateTimeImmutable
    {
        $zone = new DateTimeZone($timezone);
        $local = $utc->setTimezone($zone);
        $hour = intdiv($this->endMinutes, 60);
        $minute = $this->endMinutes % 60;

        $candidate = $local->setTime($hour, $minute);

        if ($candidate <= $local) {
            $candidate = $local->modify('+1 day')->setTime($hour, $minute);
        }

        return $candidate->setTimezone(new DateTimeZone('UTC'));
    }

    public static function formatMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @return int|null minutes since midnight, or null when not a quarter-hour HH:MM
     */
    public static function parse(string $time): ?int
    {
        if (preg_match('/^([01][0-9]|2[0-3]):(00|15|30|45)$/', $time, $parts) !== 1) {
            return null;
        }

        return ((int) $parts[1]) * 60 + (int) $parts[2];
    }
}

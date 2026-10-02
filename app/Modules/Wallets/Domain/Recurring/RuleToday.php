<?php

namespace App\Modules\Wallets\Domain\Recurring;

use DateTimeImmutable;
use DateTimeZone;

/**
 * "Today" for a rule: the calendar date, now, in the IANA timezone stored on
 * the rule at its creation (ADR-0011 rule 9, ADR-0018 rule 9). The same for
 * every member, whatever their own timezone or the server's.
 */
final class RuleToday
{
    public static function of(string $timezone, DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
    }
}

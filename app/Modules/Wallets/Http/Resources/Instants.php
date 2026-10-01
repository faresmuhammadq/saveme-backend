<?php

namespace App\Modules\Wallets\Http\Resources;

use DateTimeImmutable;
use DateTimeZone;

/**
 * UTC instants with microseconds and a trailing Z, the only instant form the
 * contract accepts (docs/api-conventions.md, Calendar dates and instants).
 */
final class Instants
{
    public static function format(?DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}

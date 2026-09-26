<?php

namespace App\Modules\Identity\Application;

use Illuminate\Support\Str;

/**
 * The two members every Identity event carries: a UUIDv7 consumers
 * deduplicate by, and the UTC instant it happened.
 */
final class Events
{
    /**
     * @return array{eventId: string, occurredAt: string}
     */
    public static function stamp(): array
    {
        return ['eventId' => (string) Str::uuid7(), 'occurredAt' => now()->utc()->format('Y-m-d\TH:i:s.u\Z')];
    }
}

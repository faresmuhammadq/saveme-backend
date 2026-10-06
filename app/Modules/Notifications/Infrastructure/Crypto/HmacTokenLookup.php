<?php

namespace App\Modules\Notifications\Infrastructure\Crypto;

use App\Modules\Notifications\Domain\Ports\TokenLookup;
use RuntimeException;

/**
 * lookup = HMAC-SHA256(token, NOTIFICATIONS_LOOKUP_KEY), lowercase hex
 * (spec 023, FR-006). Lets one token belong to one registration without
 * opening any.
 */
final class HmacTokenLookup implements TokenLookup
{
    public function for(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, self::key());
    }

    public static function key(): string
    {
        $key = base64_decode((string) config('notifications.lookup_key'), true);

        if ($key === false || strlen($key) < 32) {
            throw new RuntimeException('NOTIFICATIONS_LOOKUP_KEY must be a base64 key of at least 32 bytes.');
        }

        return $key;
    }
}

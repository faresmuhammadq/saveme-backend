<?php

namespace App\Modules\Billing\Infrastructure\Crypto;

use App\Modules\Billing\Domain\Ports\TokenLookup;
use RuntimeException;

/**
 * lookup = HMAC-SHA256(token, BILLING_PLAY_LOOKUP_KEY), lowercase hex (spec
 * 024, R-4). Lets one token belong to one purchase without opening any.
 */
final class HmacTokenLookup implements TokenLookup
{
    public function for(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, self::key());
    }

    public static function key(): string
    {
        $key = base64_decode((string) config('billing.google_play.lookup_key'), true);

        if ($key === false || strlen($key) < 32) {
            throw new RuntimeException('BILLING_PLAY_LOOKUP_KEY must be a base64 key of at least 32 bytes.');
        }

        return $key;
    }
}

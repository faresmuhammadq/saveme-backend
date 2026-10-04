<?php

namespace App\Modules\Admin\Infrastructure\Crypto;

use App\Modules\Admin\Domain\Ports\EmailLookup;
use App\Modules\Identity\Domain\Address\AddressNormaliser;
use RuntimeException;

/**
 * lookup = HMAC-SHA256(normalise(email), ADMIN_LOOKUP_KEY), lowercase hex.
 * Normalisation is Identity's domain rule, so an email means the same thing
 * in both modules (spec 018, R-10).
 */
final class HmacEmailLookup implements EmailLookup
{
    public function lookup(string $email): string
    {
        return hash_hmac('sha256', AddressNormaliser::normalise($email), self::key('lookup_key', 'ADMIN_LOOKUP_KEY'));
    }

    public static function key(string $configKey, string $variable): string
    {
        $key = base64_decode((string) config("admin.auth.{$configKey}"), true);

        if ($key === false || strlen($key) < 32) {
            throw new RuntimeException("{$variable} must be a base64 key of at least 32 bytes.");
        }

        return $key;
    }
}

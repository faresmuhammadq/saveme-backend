<?php

namespace App\Modules\Identity\Infrastructure\Crypto;

use App\Modules\Identity\Domain\Address\AddressNormaliser;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use RuntimeException;

/**
 * lookup = HMAC-SHA256(normalise(address), lookup_key), as lowercase hex
 * (security baseline, Finding a person by address). The key is separate from
 * the keyring and never stored in the database.
 */
final class HmacAddressLookup implements AddressLookup
{
    public function __construct(private readonly string $key) {}

    public static function fromConfig(): self
    {
        $key = base64_decode((string) config('identity.lookup_key'), true);

        if ($key === false || strlen($key) < 32) {
            throw new RuntimeException('IDENTITY_LOOKUP_KEY must be a base64 key of at least 32 bytes.');
        }

        return new self($key);
    }

    public function for(string $address): string
    {
        return hash_hmac('sha256', AddressNormaliser::normalise($address), $this->key);
    }
}

<?php

namespace App\Modules\Billing\Infrastructure\Crypto;

use RuntimeException;

/**
 * The key-encryption keys for claim values, by identifier (spec 020,
 * R-5), a copy of Admin's on purpose. Separate from Identity's and Admin's, so no compromise exposes another module's values.
 * They live in the deployment's secret store, never in the database.
 */
final class BillingKeyring
{
    /**
     * @param  array<string, string>  $keys  kid → base64 key
     */
    public function __construct(private readonly array $keys, private readonly ?string $activeKid) {}

    public static function fromConfig(): self
    {
        /** @var array<string, string> $keys */
        $keys = config('billing.keys', []);
        $active = config('billing.active_key');

        return new self($keys, is_string($active) ? $active : null);
    }

    public function activeKid(): string
    {
        if ($this->activeKid === null || ! isset($this->keys[$this->activeKid])) {
            throw new RuntimeException('BILLING_ACTIVE_KEY does not name a key in BILLING_KEYS.');
        }

        return $this->activeKid;
    }

    public function key(string $kid): string
    {
        $key = isset($this->keys[$kid]) ? base64_decode($this->keys[$kid], true) : false;

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException("Billing key [{$kid}] is missing or is not 32 bytes.");
        }

        return $key;
    }
}

<?php

namespace App\Modules\Admin\Infrastructure\Crypto;

use RuntimeException;

/**
 * The key-encryption keys for operator values, by identifier (spec 018,
 * R-10). Separate from Identity's, so neither compromise exposes the other.
 * They live in the deployment's secret store, never in the database.
 */
final class AdminKeyring
{
    /**
     * @param  array<string, string>  $keys  kid → base64 key
     */
    public function __construct(private readonly array $keys, private readonly ?string $activeKid) {}

    public static function fromConfig(): self
    {
        /** @var array<string, string> $keys */
        $keys = config('admin.auth.keys', []);
        $active = config('admin.auth.active_key');

        return new self($keys, is_string($active) ? $active : null);
    }

    public function activeKid(): string
    {
        if ($this->activeKid === null || ! isset($this->keys[$this->activeKid])) {
            throw new RuntimeException('ADMIN_ACTIVE_KEY does not name a key in ADMIN_KEYS.');
        }

        return $this->activeKid;
    }

    public function key(string $kid): string
    {
        $key = isset($this->keys[$kid]) ? base64_decode($this->keys[$kid], true) : false;

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException("Admin key [{$kid}] is missing or is not 32 bytes.");
        }

        return $key;
    }
}

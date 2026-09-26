<?php

namespace App\Modules\Identity\Infrastructure\Crypto;

use RuntimeException;

/**
 * The key-encryption keys, by identifier (spec 007, R-6). They live in the
 * deployment's secret store and never in the database they protect
 * (security baseline, Keys and rotation). Adding a kid and pointing the
 * active key at it starts a rotation; old kids stay readable until every
 * wrapped data key has been re-wrapped.
 */
final class Keyring
{
    /**
     * @param  array<string, string>  $keys  kid → base64 key
     */
    public function __construct(
        private readonly array $keys,
        private readonly ?string $activeKid,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, string> $keys */
        $keys = config('identity.keys', []);

        return new self($keys, config('identity.active_key'));
    }

    public function activeKid(): string
    {
        if ($this->activeKid === null || ! isset($this->keys[$this->activeKid])) {
            throw new RuntimeException('IDENTITY_ACTIVE_KEY does not name a key in IDENTITY_KEYS.');
        }

        return $this->activeKid;
    }

    public function key(string $kid): string
    {
        $key = isset($this->keys[$kid]) ? base64_decode($this->keys[$kid], true) : false;

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException("Identity key [{$kid}] is missing or is not 32 bytes.");
        }

        return $key;
    }
}

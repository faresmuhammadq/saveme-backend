<?php

namespace App\Modules\Notifications\Infrastructure\Crypto;

use RuntimeException;

/**
 * The key-encryption keys for push tokens, by identifier (spec 023, FR-006),
 * a copy of Billing's on purpose: a module never imports another module's
 * Infrastructure (P-02). Several keys may live at once: rotating means adding
 * a key and making it active; values sealed under an older key stay readable
 * until their registration is refreshed or pruned.
 */
final class NotificationsKeyring
{
    /**
     * @param  array<string, string>  $keys  kid → base64 key
     */
    public function __construct(private readonly array $keys, private readonly ?string $activeKid) {}

    public static function fromConfig(): self
    {
        /** @var array<string, string> $keys */
        $keys = config('notifications.keys', []);
        $active = config('notifications.active_key');

        return new self($keys, is_string($active) ? $active : null);
    }

    public function activeKid(): string
    {
        if ($this->activeKid === null || ! isset($this->keys[$this->activeKid])) {
            throw new RuntimeException('NOTIFICATIONS_ACTIVE_KEY does not name a key in NOTIFICATIONS_KEYS.');
        }

        return $this->activeKid;
    }

    public function key(string $kid): string
    {
        $key = isset($this->keys[$kid]) ? base64_decode($this->keys[$kid], true) : false;

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException("Notifications key [{$kid}] is missing or is not 32 bytes.");
        }

        return $key;
    }
}

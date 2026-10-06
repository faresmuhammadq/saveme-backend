<?php

namespace App\Modules\Notifications\Infrastructure\Crypto;

use App\Modules\Notifications\Domain\Ports\TokenSealer;
use RuntimeException;

/**
 * XChaCha20-Poly1305 with a fresh 24-byte nonce per value. Output:
 * `v1.{kid}.{base64}`, where kid names the key that sealed it. Associated
 * data `push_registration:{session_id}` binds a token to its registration.
 *
 * A small copy of Billing's sealer on purpose (spec 023, R-4, Complexity
 * Tracking). Exception messages never carry the token or a key.
 */
final class NotificationsSealer implements TokenSealer
{
    private const VERSION = 'v1';

    public function __construct(private readonly NotificationsKeyring $keyring) {}

    public function seal(#[\SensitiveParameter] string $token, string $sessionId): string
    {
        $kid = $this->keyring->activeKid();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $token,
            self::associatedData($sessionId),
            $nonce,
            $this->keyring->key($kid),
        );

        return self::VERSION.'.'.$kid.'.'.base64_encode($nonce.$ciphertext);
    }

    public function open(string $sealed, string $sessionId): string
    {
        $parts = explode('.', $sealed, 3);

        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new RuntimeException('Not a sealed value.');
        }

        $decoded = base64_decode($parts[2], true);
        $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($decoded === false || strlen($decoded) <= $nonceBytes) {
            throw new RuntimeException('Sealed value is corrupt.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($decoded, $nonceBytes),
            self::associatedData($sessionId),
            substr($decoded, 0, $nonceBytes),
            $this->keyring->key($parts[1]),
        );

        if ($plaintext === false) {
            throw new RuntimeException('Sealed value failed authentication.');
        }

        return $plaintext;
    }

    private static function associatedData(string $sessionId): string
    {
        return "push_registration:{$sessionId}";
    }
}

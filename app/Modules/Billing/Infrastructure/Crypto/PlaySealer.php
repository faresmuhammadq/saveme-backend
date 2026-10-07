<?php

namespace App\Modules\Billing\Infrastructure\Crypto;

use App\Modules\Billing\Domain\Ports\PlayTokenSealer;
use RuntimeException;

/**
 * XChaCha20-Poly1305 for purchase tokens (spec 024, R-4), under Billing's
 * keyring. Output `v1.{kid}.{base64}`; associated data
 * `{subject}:{id}:token` binds a value to its row. A small sibling of
 * `BillingSealer`, which binds claim fields.
 */
final class PlaySealer implements PlayTokenSealer
{
    private const VERSION = 'v1';

    public function __construct(private readonly BillingKeyring $keyring) {}

    public function seal(#[\SensitiveParameter] string $token, string $subject, string $id): string
    {
        $kid = $this->keyring->activeKid();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($token, "{$subject}:{$id}:token", $nonce, $this->keyring->key($kid));

        return self::VERSION.'.'.$kid.'.'.base64_encode($nonce.$ciphertext);
    }

    public function open(string $sealed, string $subject, string $id): string
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
            "{$subject}:{$id}:token",
            substr($decoded, 0, $nonceBytes),
            $this->keyring->key($parts[1]),
        );

        if ($plaintext === false) {
            throw new RuntimeException('Sealed value failed authentication.');
        }

        return $plaintext;
    }
}

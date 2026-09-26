<?php

namespace App\Modules\Identity\Infrastructure\Crypto;

use RuntimeException;

/**
 * XChaCha20-Poly1305 (authenticated), a fresh 24-byte nonce per value, with
 * associated data binding a value to its owner. Output: `v1.{ref}.{base64}`,
 * where ref names the key that produced it (security baseline D4).
 */
final class Sealer
{
    private const VERSION = 'v1';

    public static function seal(string $plaintext, string $key, string $ref, string $associatedData): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData, $nonce, $key);

        return self::VERSION.'.'.$ref.'.'.base64_encode($nonce.$ciphertext);
    }

    /**
     * @return array{ref: string, payload: string}
     */
    public static function parse(string $sealed): array
    {
        $parts = explode('.', $sealed, 3);

        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new RuntimeException('Not a sealed value.');
        }

        return ['ref' => $parts[1], 'payload' => $parts[2]];
    }

    public static function open(string $sealed, string $key, string $associatedData): string
    {
        $decoded = base64_decode(self::parse($sealed)['payload'], true);

        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('Sealed value is corrupt.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            $associatedData,
            $nonce,
            $key,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Sealed value failed authentication.');
        }

        return $plaintext;
    }
}

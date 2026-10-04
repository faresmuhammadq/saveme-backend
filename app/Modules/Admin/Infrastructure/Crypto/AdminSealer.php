<?php

namespace App\Modules\Admin\Infrastructure\Crypto;

use App\Modules\Admin\Domain\Ports\SecretSealer;
use RuntimeException;

/**
 * XChaCha20-Poly1305 with a fresh 24-byte nonce per value. Output:
 * `v1.{ref}.{base64}`, where ref names the key that sealed it. Associated
 * data `operator:{id}:{field}` binds a value to its owner and column.
 *
 * This is a small copy of Identity's Sealer on purpose: a module never
 * imports another module's Infrastructure (P-02), and the shared kernel
 * holds no infrastructure (spec 018, R-10, Complexity Tracking).
 */
final class AdminSealer implements SecretSealer
{
    private const VERSION = 'v1';

    public function __construct(private readonly AdminKeyring $keyring) {}

    public function seal(string $plaintext, string $operatorId, string $field): string
    {
        $ref = $this->keyring->activeKid();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::associatedData($operatorId, $field),
            $nonce,
            $this->keyring->key($ref),
        );

        return self::VERSION.'.'.$ref.'.'.base64_encode($nonce.$ciphertext);
    }

    public function open(string $sealed, string $operatorId, string $field): string
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
            self::associatedData($operatorId, $field),
            substr($decoded, 0, $nonceBytes),
            $this->keyring->key($parts[1]),
        );

        if ($plaintext === false) {
            throw new RuntimeException('Sealed value failed authentication.');
        }

        return $plaintext;
    }

    private static function associatedData(string $operatorId, string $field): string
    {
        return "operator:{$operatorId}:{$field}";
    }
}

<?php

namespace App\Modules\Billing\Infrastructure\Crypto;

use App\Modules\Billing\Domain\Ports\ClaimSealer;
use RuntimeException;

/**
 * XChaCha20-Poly1305 with a fresh 24-byte nonce per value. Output:
 * `v1.{ref}.{base64}`, where ref names the key that sealed it. Associated
 * data `payment_claim:{id}:{field}` binds a value to its claim and column.
 *
 * A small copy of Admin's sealer on purpose: a module never imports another
 * module's Infrastructure (P-02), and a claim has no user whose data key
 * could seal it (spec 020, R-5, Complexity Tracking).
 */
final class BillingSealer implements ClaimSealer
{
    private const VERSION = 'v1';

    public function __construct(private readonly BillingKeyring $keyring) {}

    public function seal(string $plaintext, string $claimId, string $field): string
    {
        $ref = $this->keyring->activeKid();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::associatedData($claimId, $field),
            $nonce,
            $this->keyring->key($ref),
        );

        return self::VERSION.'.'.$ref.'.'.base64_encode($nonce.$ciphertext);
    }

    public function open(string $sealed, string $claimId, string $field): string
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
            self::associatedData($claimId, $field),
            substr($decoded, 0, $nonceBytes),
            $this->keyring->key($parts[1]),
        );

        if ($plaintext === false) {
            throw new RuntimeException('Sealed value failed authentication.');
        }

        return $plaintext;
    }

    private static function associatedData(string $claimId, string $field): string
    {
        return "payment_claim:{$claimId}:{$field}";
    }
}

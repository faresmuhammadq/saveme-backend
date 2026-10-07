<?php

namespace App\Modules\Billing\Infrastructure\GooglePlay;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * An RS256 JSON Web Token (RFC 7515), signed with ext-openssl: base64url
 * without padding, SHA-256 with RSA (spec 024, FR-059). A copy of
 * Notifications' on purpose (P-02 forbids importing another module's
 * Infrastructure, Complexity Tracking).
 */
final class Jwt
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    public static function rs256(array $header, array $claims, OpenSSLAsymmetricKey $key): string
    {
        $signingInput = self::base64Url((string) json_encode($header, JSON_UNESCAPED_SLASHES))
            .'.'.self::base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The Google Play assertion could not be signed.');
        }

        return $signingInput.'.'.self::base64Url($signature);
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Strict base64url decoding (no padding, no stray characters).
     */
    public static function decodeBase64Url(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}

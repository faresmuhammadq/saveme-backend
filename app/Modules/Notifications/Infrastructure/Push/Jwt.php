<?php

namespace App\Modules\Notifications\Infrastructure\Push;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * An RS256 JSON Web Token (RFC 7515), signed with ext-openssl: base64url
 * without padding, SHA-256 with RSA (spec 023, FR-039).
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
            throw new RuntimeException('The push assertion could not be signed.');
        }

        return $signingInput.'.'.self::base64Url($signature);
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

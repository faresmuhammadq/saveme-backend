<?php

namespace App\Modules\Billing\Infrastructure\GooglePlay;

use App\Modules\Billing\Domain\GooglePlay\OidcClaims;
use DateTimeImmutable;

/**
 * Verifies the OIDC bearer token of a Cloud Pub/Sub push without a library
 * (spec 024, FR-030, R-8; security baseline, Messages from the payment
 * provider). `RS256` only; the key comes from Google's certificate endpoint,
 * never from the token; then the claim checks of `OidcClaims`.
 */
final class PushTokenVerifier
{
    public function __construct(private readonly GoogleCertificates $certificates) {}

    /**
     * @return ?string null when the token is Google's and valid for us; otherwise the reason code
     *
     * @throws CertificatesUnavailable
     */
    public function failure(#[\SensitiveParameter] ?string $bearer, ?DateTimeImmutable $now = null): ?string
    {
        $parts = explode('.', (string) $bearer);

        if (count($parts) !== 3) {
            return 'malformed';
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = json_decode((string) Jwt::decodeBase64Url($encodedHeader), true);

        if (! is_array($header)) {
            return 'malformed';
        }

        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null) || $header['kid'] === '') {
            return 'algorithm';
        }

        $claims = json_decode((string) Jwt::decodeBase64Url($encodedClaims), true);
        $signature = Jwt::decodeBase64Url($encodedSignature);

        if (! is_array($claims) || $signature === null) {
            return 'malformed';
        }

        $pem = $this->certificates->pemFor($header['kid']);
        $key = $pem === null ? false : openssl_pkey_get_public($pem);

        if ($key === false) {
            return 'signature';
        }

        if (openssl_verify($encodedHeader.'.'.$encodedClaims, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return 'signature';
        }

        return OidcClaims::failure(
            $claims,
            (string) config('billing.google_play.push.audience'),
            (string) config('billing.google_play.push.service_account'),
            $now ?? new DateTimeImmutable,
        );
    }
}

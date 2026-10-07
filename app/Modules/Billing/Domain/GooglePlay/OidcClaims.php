<?php

namespace App\Modules\Billing\Domain\GooglePlay;

use DateTimeImmutable;

/**
 * The claim checks of a Pub/Sub push token (spec 024, FR-030, R-8; security
 * baseline, Messages from the payment provider). Pure: the signature is
 * checked elsewhere. Returns the reason code of the first failing check, or
 * null when every claim holds.
 */
final class OidcClaims
{
    public const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    private const IAT_WINDOW_SECONDS = 300;

    private const EXP_LEEWAY_SECONDS = 60;

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function failure(array $claims, string $audience, string $serviceAccount, DateTimeImmutable $now): ?string
    {
        if (! is_string($claims['iss'] ?? null) || ! in_array($claims['iss'], self::ISSUERS, true)) {
            return 'issuer';
        }

        if ($audience === '' || ! is_string($claims['aud'] ?? null) || ! hash_equals($audience, $claims['aud'])) {
            return 'audience';
        }

        if ($serviceAccount === '' || ! is_string($claims['email'] ?? null) || ! hash_equals($serviceAccount, $claims['email'])) {
            return 'email';
        }

        if (($claims['email_verified'] ?? null) !== true) {
            return 'email_unverified';
        }

        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;

        if (! is_int($iat) && ! is_float($iat) || abs($now->getTimestamp() - $iat) > self::IAT_WINDOW_SECONDS) {
            return 'time';
        }

        if (! is_int($exp) && ! is_float($exp) || $exp <= $now->getTimestamp() - self::EXP_LEEWAY_SECONDS) {
            return 'expired';
        }

        return null;
    }
}

<?php

namespace App\Modules\Billing\Infrastructure\GooglePlay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google's public signing certificates for OIDC tokens (spec 024, R-8,
 * FR-031): PEM X.509 certificates by key id, fetched only from the
 * configured Google endpoint over HTTPS, cached for the lifetime Google's
 * cache headers give (clamped 5 minutes to 24 hours), refreshed at most once
 * a minute when a key id is unknown. Never taken from the request or the token.
 */
final class GoogleCertificates
{
    public const CACHE_KEY = 'billing:play:oidc-certs';

    private const MIN_SECONDS = 300;

    private const MAX_SECONDS = 86400;

    /**
     * @return array<string, string> kid => PEM certificate
     *
     * @throws CertificatesUnavailable when none are cached and none can be fetched
     */
    public function all(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        return $this->refresh() ?? throw new CertificatesUnavailable;
    }

    /**
     * The PEM of one key, refreshing once (per minute) when the kid is unknown.
     *
     * @throws CertificatesUnavailable
     */
    public function pemFor(string $kid): ?string
    {
        $certs = $this->all();

        if (isset($certs[$kid])) {
            return $certs[$kid];
        }

        if (! Cache::add(self::CACHE_KEY.':refresh', true, 60)) {
            return null;
        }

        return ($this->refresh() ?? $certs)[$kid] ?? null;
    }

    /**
     * @return array<string, string>|null
     */
    private function refresh(): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('billing.google_play.timeout'))
                ->connectTimeout((int) config('billing.google_play.connect_timeout'))
                ->get((string) config('billing.google_play.push.certs_url'));
        } catch (Throwable) {
            return null;
        }

        $certs = $response->json();

        if (! $response->successful() || ! is_array($certs) || $certs === []) {
            return null;
        }

        $certs = array_filter($certs, fn (mixed $pem, mixed $kid): bool => is_string($kid) && is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE'), ARRAY_FILTER_USE_BOTH);

        if ($certs === []) {
            return null;
        }

        Cache::put(self::CACHE_KEY, $certs, self::lifetime((string) $response->header('Cache-Control')));

        return $certs;
    }

    private static function lifetime(string $cacheControl): int
    {
        if (preg_match('/max-age=(\d+)/i', $cacheControl, $match) !== 1) {
            return self::MIN_SECONDS;
        }

        return min(self::MAX_SECONDS, max(self::MIN_SECONDS, (int) $match[1]));
    }
}

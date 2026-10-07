<?php

namespace App\Modules\Billing\Infrastructure\GooglePlay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * An OAuth 2.0 access token for the Google Play Developer API through the
 * JWT bearer grant (RFC 7523, spec 024, FR-059): a service-account assertion
 * signed RS256, exchanged at the token endpoint, cached until 5 minutes
 * before it expires behind a lock so concurrent workers mint one. The
 * assertion and the token are never logged and never put in an exception
 * message.
 */
final class ServiceAccountTokenSource
{
    public const CACHE_KEY = 'billing:play:access-token';

    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    private const EARLY_REFRESH_SECONDS = 300;

    public function __construct(private readonly ServiceAccountKey $key) {}

    public function token(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return Cache::lock(self::CACHE_KEY.':mint', 10)->block(10, function (): string {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            return $this->mint();
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function mint(): string
    {
        $issuedAt = time();
        $assertion = Jwt::rs256(
            ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->key->keyId],
            [
                'iss' => $this->key->clientEmail,
                'scope' => self::SCOPE,
                'aud' => $this->key->tokenUri,
                'iat' => $issuedAt,
                'exp' => $issuedAt + 3600,
            ],
            $this->key->privateKey(),
        );

        $response = Http::asForm()
            ->acceptJson()
            ->timeout((int) config('billing.google_play.timeout'))
            ->connectTimeout((int) config('billing.google_play.connect_timeout'))
            ->post($this->key->tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Google refused to issue an access token (status '.$response->status().').');
        }

        $keepFor = (int) ($response->json('expires_in') ?? 3600) - self::EARLY_REFRESH_SECONDS;

        if ($keepFor > 0) {
            Cache::put(self::CACHE_KEY, $token, $keepFor);
        }

        return $token;
    }
}

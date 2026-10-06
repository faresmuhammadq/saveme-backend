<?php

namespace App\Modules\Notifications\Infrastructure\Push;

use App\Modules\Notifications\Domain\Ports\AccessTokenSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * An OAuth 2.0 access token for FCM through the JWT bearer grant (RFC 7523,
 * spec 023, FR-039): a service-account assertion signed RS256, exchanged at
 * the token endpoint, cached until 5 minutes before it expires behind a lock
 * so concurrent workers mint one. The assertion and the token are never
 * logged and never put in an exception message.
 */
final class ServiceAccountTokenSource implements AccessTokenSource
{
    public const CACHE_KEY = 'notifications:fcm:access-token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const EARLY_REFRESH_SECONDS = 300;

    public function __construct(private readonly ServiceAccountKey $key) {}

    public function token(): string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return Cache::lock(self::CACHE_KEY.':lock', 30)->block(15, function (): string {
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
            ->timeout((int) config('notifications.push.fcm.timeout'))
            ->connectTimeout((int) config('notifications.push.fcm.connect_timeout'))
            ->post($this->key->tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('The push service refused to issue an access token (status '.$response->status().').');
        }

        $lifetime = (int) ($response->json('expires_in') ?? 3600);
        $keepFor = $lifetime - self::EARLY_REFRESH_SECONDS;

        if ($keepFor > 0) {
            Cache::put(self::CACHE_KEY, $token, $keepFor);
        }

        return $token;
    }
}

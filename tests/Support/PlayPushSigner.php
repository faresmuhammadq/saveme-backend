<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A key pair and self-signed certificate generated for the test run, served
 * as Google's certificate set through `Http::fake`, and the bearer tokens a
 * Pub/Sub push would carry, signed with it (spec 024, FR-061). Claims and
 * header members are overridable so each rejection can be exercised.
 */
final class PlayPushSigner
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

    private static ?self $shared = null;

    private function __construct(
        public readonly string $kid,
        private readonly OpenSSLAsymmetricKey $key,
        public readonly string $certificate,
    ) {}

    /**
     * One key pair per test process: generating RSA keys is slow.
     */
    public static function make(string $kid = 'play-test-kid-1'): self
    {
        if (self::$shared !== null && self::$shared->kid === $kid) {
            return self::$shared;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => 'play-push-test'], $key);
        $cert = $csr === false || $csr === true ? false : openssl_csr_sign($csr, null, $key, 1);

        if ($key === false || $cert === false || ! openssl_x509_export($cert, $pem)) {
            throw new RuntimeException('Could not generate a test certificate.');
        }

        return self::$shared = new self($kid, $key, $pem);
    }

    /**
     * Serves this key as Google's certificate set, cacheable for an hour.
     */
    public function serveCertificates(?string $kid = null): void
    {
        Http::fake([self::CERTS_URL => Http::response([$kid ?? $this->kid => $this->certificate], 200, ['Cache-Control' => 'public, max-age=3600'])]);
    }

    /**
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => (string) config('billing.google_play.push.audience'),
            'email' => (string) config('billing.google_play.push.service_account'),
            'email_verified' => true,
            'iat' => time(),
            'exp' => time() + 3600,
            'sub' => '1234567890',
        ];
    }

    /**
     * @param  array<string, mixed>  $claims  overrides; a null value removes the claim
     * @param  array<string, mixed>  $header  overrides of the JOSE header
     */
    public function token(array $claims = [], array $header = []): string
    {
        $header = [...['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid], ...$header];
        $claims = array_filter([...$this->claims(), ...$claims], fn (mixed $value): bool => $value !== null);

        $input = self::b64(json_encode($header)).'.'.self::b64(json_encode($claims));

        if (($header['alg'] ?? null) === 'none') {
            return $input.'.';
        }

        openssl_sign($input, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64($signature);
    }

    /**
     * A token signed by some other key under this key id: the signature cannot verify.
     */
    public function forgedToken(array $claims = []): string
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $input = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid])).'.'.self::b64(json_encode([...$this->claims(), ...$claims]));
        openssl_sign($input, $signature, $other, OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64($signature);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

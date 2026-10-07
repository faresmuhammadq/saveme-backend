<?php

namespace App\Modules\Billing\Infrastructure\GooglePlay;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A Google service-account key (spec 024, FR-058): read from a path or from
 * the JSON base64-encoded. Never in an exception message, a log line or a
 * dump; the private key is parsed only when a token is signed.
 */
final class ServiceAccountKey
{
    private function __construct(
        public readonly string $clientEmail,
        public readonly string $keyId,
        public readonly string $tokenUri,
        #[\SensitiveParameter] private readonly string $privateKey,
    ) {}

    /**
     * @param  string  $source  a readable path to the JSON file, or the JSON itself base64-encoded
     */
    public static function from(#[\SensitiveParameter] string $source, string $defaultTokenUri): self
    {
        $json = is_file($source) ? file_get_contents($source) : base64_decode($source, true);

        if (! is_string($json) || $json === '') {
            throw new RuntimeException('The Google Play service-account credentials are not readable.');
        }

        $parts = json_decode($json, true);

        foreach (['client_email', 'private_key', 'private_key_id'] as $member) {
            if (! is_array($parts) || ! isset($parts[$member]) || ! is_string($parts[$member]) || $parts[$member] === '') {
                throw new RuntimeException("The Google Play service-account credentials lack [{$member}].");
            }
        }

        $uri = isset($parts['token_uri']) && is_string($parts['token_uri']) && $parts['token_uri'] !== '' ? $parts['token_uri'] : $defaultTokenUri;

        return new self($parts['client_email'], $parts['private_key_id'], $uri, $parts['private_key']);
    }

    public static function fromConfig(): self
    {
        return self::from((string) config('billing.google_play.credentials'), (string) config('billing.google_play.token_uri'));
    }

    public function privateKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_get_private($this->privateKey) ?: throw new RuntimeException('The Google Play service-account private key cannot be loaded.');
    }

    /**
     * Keeps the key out of dumps and traces.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['clientEmail' => $this->clientEmail, 'privateKey' => '[redacted]'];
    }
}

<?php

namespace App\Modules\Sharing\Domain;

/**
 * An invitation's token: 32 random bytes, base64url without padding (43
 * characters), kept only as its SHA-256 hash, like Identity's links (spec
 * 016, R-2). The value exists once, in the create's answer or in the mail,
 * and is never logged or stored readable.
 */
final readonly class InvitationSecret
{
    public const LENGTH = 43;

    public const PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    private function __construct(public string $value) {}

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    /**
     * The secret behind a token a caller sent, or null when it cannot be one.
     */
    public static function fromToken(string $token): ?self
    {
        return preg_match(self::PATTERN, $token) === 1 ? new self($token) : null;
    }

    public function hash(): string
    {
        return self::hashOf($this->value);
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }
}

<?php

namespace App\Modules\Notifications\Domain\Delivery;

/**
 * What a gateway answers for one destination (spec 023, FR-038): accepted,
 * the token is no longer valid, try again later (optionally after a number
 * of seconds), or failed for good. `code` is the provider's error code only
 * (for example UNAVAILABLE), never a body.
 */
final readonly class GatewayOutcome
{
    public const ACCEPTED = 'accepted';

    public const INVALID_TOKEN = 'invalid_token';

    public const RETRY_LATER = 'retry_later';

    public const FAILED = 'failed';

    private function __construct(
        public string $kind,
        public ?int $retryAfterSeconds = null,
        public ?string $code = null,
    ) {}

    public static function accepted(): self
    {
        return new self(self::ACCEPTED);
    }

    public static function invalidToken(?string $code = null): self
    {
        return new self(self::INVALID_TOKEN, code: $code);
    }

    public static function retryLater(?int $seconds = null, ?string $code = null): self
    {
        return new self(self::RETRY_LATER, $seconds, $code);
    }

    public static function failed(?string $code = null): self
    {
        return new self(self::FAILED, code: $code);
    }

    public function isAccepted(): bool
    {
        return $this->kind === self::ACCEPTED;
    }

    public function isInvalidToken(): bool
    {
        return $this->kind === self::INVALID_TOKEN;
    }

    public function isRetryable(): bool
    {
        return $this->kind === self::RETRY_LATER;
    }
}

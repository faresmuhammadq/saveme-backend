<?php

namespace App\Modules\Billing\Domain\GooglePlay;

/**
 * The closed set of answers the Play gateway gives (spec 024, FR-056):
 * a purchase record, not found / invalid, unavailable with a retry hint,
 * acknowledged, or already acknowledged.
 */
final readonly class GatewayOutcome
{
    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    public const UNAVAILABLE = 'unavailable';

    public const ACKNOWLEDGED = 'acknowledged';

    public const ALREADY_ACKNOWLEDGED = 'already_acknowledged';

    private function __construct(
        public string $kind,
        public ?PlayPurchaseRecord $record = null,
        public int $retryAfterSeconds = 60,
    ) {}

    public static function found(PlayPurchaseRecord $record): self
    {
        return new self(self::FOUND, $record);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public static function unavailable(int $retryAfterSeconds = 60): self
    {
        return new self(self::UNAVAILABLE, retryAfterSeconds: $retryAfterSeconds);
    }

    public static function acknowledged(): self
    {
        return new self(self::ACKNOWLEDGED);
    }

    public static function alreadyAcknowledged(): self
    {
        return new self(self::ALREADY_ACKNOWLEDGED);
    }

    public function isFound(): bool
    {
        return $this->kind === self::FOUND;
    }

    public function isUnavailable(): bool
    {
        return $this->kind === self::UNAVAILABLE;
    }

    public function isNotFound(): bool
    {
        return $this->kind === self::NOT_FOUND;
    }

    /**
     * Whether an acknowledgement is now recorded at Google, by this call or earlier.
     */
    public function isAcknowledgedAtGoogle(): bool
    {
        return $this->kind === self::ACKNOWLEDGED || $this->kind === self::ALREADY_ACKNOWLEDGED;
    }
}

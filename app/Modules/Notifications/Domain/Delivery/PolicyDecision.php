<?php

namespace App\Modules\Notifications\Domain\Delivery;

use DateTimeImmutable;

/**
 * What the delivery policy decides for one notification: send it now, hold it
 * until quiet hours end, or skip it for a reason.
 */
final readonly class PolicyDecision
{
    private function __construct(
        public string $action,
        public ?SkipReason $reason = null,
        public ?DateTimeImmutable $until = null,
    ) {}

    public static function send(): self
    {
        return new self('send');
    }

    public static function hold(DateTimeImmutable $until): self
    {
        return new self('hold', until: $until);
    }

    public static function skip(SkipReason $reason): self
    {
        return new self('skip', $reason);
    }

    public function isSend(): bool
    {
        return $this->action === 'send';
    }

    public function isHold(): bool
    {
        return $this->action === 'hold';
    }

    public function isSkip(): bool
    {
        return $this->action === 'skip';
    }
}

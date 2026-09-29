<?php

namespace App\Modules\Billing\Domain\Subscriptions;

use DateTimeImmutable;

/**
 * A Subscription as the application sees it.
 */
final readonly class SubscriptionRecord
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $planId,
        public string $provider,
        public string $providerRef,
        public SubscriptionStatus $status,
        public DateTimeImmutable $currentPeriodStart,
        public ?DateTimeImmutable $currentPeriodEnd,
        public ?DateTimeImmutable $graceUntil,
        public bool $autoRenew,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Whether, by its dates alone, this subscription still grants at $now.
     * Resolution never waits for the lapse to update the status (FR-013).
     */
    public function grantsAt(DateTimeImmutable $now): bool
    {
        if (! $this->status->grants()) {
            return false;
        }

        $until = $this->graceUntil ?? $this->currentPeriodEnd;

        return $until === null || $until > $now;
    }
}

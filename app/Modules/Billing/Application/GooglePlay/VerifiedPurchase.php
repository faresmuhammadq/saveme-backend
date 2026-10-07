<?php

namespace App\Modules\Billing\Application\GooglePlay;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One verified purchase as the app sees it (spec 024, FR-011): Google's
 * state as now recorded. Never the token, the order id or the reference.
 */
final readonly class VerifiedPurchase
{
    public function __construct(
        public string $purchaseId,
        public string $productId,
        public string $state,
        public bool $acknowledged,
        public bool $autoRenew,
        public ?DateTimeImmutable $currentPeriodEnd,
        public bool $test,
        public bool $newlyLinked,
    ) {}

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    /**
     * @return array{product_id: string, state: string, acknowledged: bool, auto_renew: bool, current_period_end: ?string, test: bool}
     */
    public function toWire(): array
    {
        return [
            'product_id' => $this->productId,
            'state' => $this->state,
            'acknowledged' => $this->acknowledged,
            'auto_renew' => $this->autoRenew,
            'current_period_end' => $this->currentPeriodEnd?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'test' => $this->test,
        ];
    }
}

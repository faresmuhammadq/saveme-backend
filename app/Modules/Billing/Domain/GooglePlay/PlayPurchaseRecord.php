<?php

namespace App\Modules\Billing\Domain\GooglePlay;

use DateTimeImmutable;

/**
 * What Google reports about one purchase token (spec 024, R-1): a domain
 * record, never Google's wire shape, so the adapter can be swapped.
 */
final readonly class PlayPurchaseRecord
{
    public function __construct(
        public string $productId,
        public PlayProductKind $kind,
        public PlayState $state,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $graceUntil,
        public DateTimeImmutable $purchasedAt,
        public bool $autoRenew,
        public bool $acknowledged,
        public ?string $obfuscatedRef,
        public ?string $linkedToken,
        public bool $isTest,
        public ?string $basePlanId,
    ) {}

    public function withState(PlayState $state): self
    {
        return new self($this->productId, $this->kind, $state, $this->expiresAt, $this->graceUntil, $this->purchasedAt, $this->autoRenew, $this->acknowledged, $this->obfuscatedRef, $this->linkedToken, $this->isTest, $this->basePlanId);
    }

    public function withAcknowledged(bool $acknowledged): self
    {
        return new self($this->productId, $this->kind, $this->state, $this->expiresAt, $this->graceUntil, $this->purchasedAt, $this->autoRenew, $acknowledged, $this->obfuscatedRef, $this->linkedToken, $this->isTest, $this->basePlanId);
    }
}

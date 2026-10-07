<?php

namespace App\Modules\Billing\Domain\GooglePlay;

use DateTimeImmutable;

/**
 * A Play Purchase as stored (data-model.md): the sealed token stays sealed.
 */
final readonly class StoredPlayPurchase
{
    public function __construct(
        public string $id,
        public string $tokenLookup,
        public ?string $tokenSealed,
        public PlayProductKind $kind,
        public string $productId,
        public ?string $userId,
        public ?string $subscriptionId,
        public ?string $obfuscatedRef,
        public ?string $replacesLookup,
        public PlayState $playState,
        public ?DateTimeImmutable $acknowledgedAt,
        public bool $isTest,
        public ?string $basePlanId,
        public DateTimeImmutable $purchasedAt,
        public ?DateTimeImmutable $syncedAt,
        public ?DateTimeImmutable $unreachableSince,
        public ?DateTimeImmutable $endedByAccountPurgeAt,
    ) {}

    public function isLinked(): bool
    {
        return $this->userId !== null;
    }
}

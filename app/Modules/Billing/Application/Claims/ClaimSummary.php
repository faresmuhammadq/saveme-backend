<?php

namespace App\Modules\Billing\Application\Claims;

use App\Modules\Billing\Domain\Claims\ClaimRecord;
use DateTimeImmutable;

/**
 * A claim as the review queue lists it: no address, no receipt, no review
 * note (spec 020, FR-045).
 */
final readonly class ClaimSummary
{
    public function __construct(
        public string $id,
        public string $planId,
        public int $amountMinor,
        public string $currency,
        public string $reference,
        public string $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public int $duplicateCount,
    ) {}

    public static function of(ClaimRecord $claim, int $duplicateCount = 0): self
    {
        return new self(
            $claim->id,
            $claim->planId,
            $claim->amountMinor,
            $claim->currency,
            $claim->reference,
            $claim->status->value,
            $claim->createdAt,
            $claim->expiresAt,
            $duplicateCount,
        );
    }
}

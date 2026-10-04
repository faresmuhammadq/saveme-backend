<?php

namespace App\Modules\Billing\Domain\RedeemCodes;

use DateTimeImmutable;

/**
 * A Redeem Code as the application sees it: never the code itself, only its
 * hash (spec 020, FR-022).
 */
final readonly class RedeemCodeRecord
{
    public function __construct(
        public string $id,
        public string $claimId,
        public string $codeHash,
        public RedeemCodeStatus $status,
        public DateTimeImmutable $expiresAt,
        public ?string $redeemedBy,
        public ?DateTimeImmutable $redeemedAt,
        public ?string $subscriptionId,
        public ?DateTimeImmutable $revokedAt,
    ) {}
}

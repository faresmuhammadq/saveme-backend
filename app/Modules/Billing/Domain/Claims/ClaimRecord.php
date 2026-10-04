<?php

namespace App\Modules\Billing\Domain\Claims;

use DateTimeImmutable;

/**
 * A Payment Claim as the application sees it. The address and the receipt
 * are still sealed here; only the application opens them.
 */
final readonly class ClaimRecord
{
    public function __construct(
        public string $id,
        public string $planId,
        public int $amountMinor,
        public string $currency,
        public string $reference,
        public ?string $addressSealed,
        public ?string $addressLookup,
        public string $locale,
        public ?string $receiptPath,
        public ?string $receiptMediaType,
        public ?int $receiptBytes,
        public ClaimStatus $status,
        public DateTimeImmutable $expiresAt,
        public ?string $reviewedBy,
        public ?DateTimeImmutable $reviewedAt,
        public ?RejectReason $rejectReason,
        public ?string $reviewNote,
        public ?DateTimeImmutable $closedAt,
        public ?DateTimeImmutable $erasedAt,
        public DateTimeImmutable $createdAt,
    ) {}
}

<?php

namespace App\Modules\Billing\Application\Claims;

use DateTimeImmutable;

/**
 * One claim as an operator sees it (spec 020, FR-009, FR-045), address opened.
 * Whoever reads it records an `admin_action` `claim_address_read`.
 */
final readonly class ClaimDetail
{
    /**
     * @param  list<ClaimSummary>  $duplicates
     */
    public function __construct(
        public ClaimSummary $summary,
        public string $locale,
        public ?string $address,
        public bool $accountExists,
        public bool $hasReceipt,
        public ?string $receiptMediaType,
        public ?int $receiptBytes,
        public ?string $reviewedBy,
        public ?DateTimeImmutable $reviewedAt,
        public ?string $rejectReason,
        public ?string $reviewNote,
        public ?DateTimeImmutable $closedAt,
        public ?DateTimeImmutable $erasedAt,
        public ?string $codeStatus,
        public ?DateTimeImmutable $codeExpiresAt,
        public array $duplicates,
    ) {}
}

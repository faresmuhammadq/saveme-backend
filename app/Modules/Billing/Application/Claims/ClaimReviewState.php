<?php

namespace App\Modules\Billing\Application\Claims;

use DateTimeImmutable;

/**
 * What a claim review page refreshes after an action (spec 021, R-3): the
 * review facts and the code state, and no address, so reading it is not a
 * recorded read.
 */
final readonly class ClaimReviewState
{
    public function __construct(
        public string $status,
        public DateTimeImmutable $expiresAt,
        public ?string $reviewedBy,
        public ?DateTimeImmutable $reviewedAt,
        public ?string $rejectReason,
        public ?string $reviewNote,
        public ?DateTimeImmutable $closedAt,
        public ?DateTimeImmutable $erasedAt,
        public ?string $codeStatus,
        public ?DateTimeImmutable $codeExpiresAt,
    ) {}
}

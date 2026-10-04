<?php

namespace App\Modules\Billing\Application\Claims;

use DateTimeImmutable;

/**
 * What the website is told about a stored claim (spec 020, FR-008): never the
 * address, the reference or the receipt.
 */
final readonly class SubmittedClaim
{
    public function __construct(
        public string $id,
        public string $status,
        public string $planId,
        public string $planName,
        public int $amountMinor,
        public string $currency,
        public DateTimeImmutable $expiresAt,
    ) {}
}

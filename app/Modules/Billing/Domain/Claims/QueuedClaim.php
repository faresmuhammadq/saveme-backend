<?php

namespace App\Modules\Billing\Domain\Claims;

/**
 * A claim in the review queue with the number of other claims carrying the
 * same reference (spec 020, FR-011). No address.
 */
final readonly class QueuedClaim
{
    public function __construct(
        public ClaimRecord $claim,
        public int $duplicateCount,
    ) {}
}

<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The claim is not submitted, or its 7 days have passed: it cannot be approved or rejected (spec 020, FR-015, FR-016). Raised to operators only; no HTTP route.
 */
final class ClaimNotReviewable extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::VersionConflict, detailKey: 'billing.refusals.claim_not_reviewable');
    }
}

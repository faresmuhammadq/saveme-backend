<?php

namespace App\Modules\Billing\Application\Claims;

use App\Modules\Billing\Domain\Claims\ClaimStatus;

/**
 * The four tabs of the claim queue (spec 021, FR-015): a claim status an
 * operator may list by.
 */
enum ClaimFilter: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function status(): ClaimStatus
    {
        return ClaimStatus::from($this->value);
    }
}

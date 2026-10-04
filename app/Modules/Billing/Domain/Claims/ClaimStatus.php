<?php

namespace App\Modules\Billing\Domain\Claims;

/**
 * A Payment Claim's states (glossary; spec 020, FR-015): `submitted` becomes
 * `approved`, `rejected` or `expired`, and never changes again.
 */
enum ClaimStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
}

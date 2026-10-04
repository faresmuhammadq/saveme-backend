<?php

namespace App\Modules\Billing\Domain\Claims;

/**
 * The fixed list of reasons an operator may give when rejecting a claim
 * (spec 020, FR-018). A closed list, because the claimant's mail states the
 * reason in their language (P-14); free text stays in the internal note.
 */
enum RejectReason: string
{
    case ReferenceNotFound = 'reference_not_found';
    case AmountMismatch = 'amount_mismatch';
    case Duplicate = 'duplicate';
    case Other = 'other';
}

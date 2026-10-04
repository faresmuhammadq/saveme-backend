<?php

namespace App\Modules\Identity\Application\Audit\Details;

/**
 * A `claim_reviewed` entry (written by Billing, spec 020, FR-036): the
 * outcome, and the reject reason only when the claim was rejected. Never an
 * address, reference, amount or code, and never the review note or a revoke
 * reason, which are free text and may hold personal detail.
 */
final readonly class ClaimReviewedDetail implements AuditDetail
{
    /**
     * @param  'approved'|'rejected'|'code_revoked'  $outcome
     * @param  string|null  $reason  the reject reason, for a rejection only
     */
    public function __construct(
        private string $outcome,
        private ?string $reason = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'outcome' => $this->outcome,
            'reason' => $this->outcome === 'rejected' ? $this->reason : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}

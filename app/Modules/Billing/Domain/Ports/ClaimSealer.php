<?php

namespace App\Modules\Billing\Domain\Ports;

/**
 * Authenticated encryption of a claim's address and receipt, bound to the
 * claim and the field (spec 020, R-5).
 */
interface ClaimSealer
{
    public function seal(string $plaintext, string $claimId, string $field): string;

    public function open(string $sealed, string $claimId, string $field): string;
}

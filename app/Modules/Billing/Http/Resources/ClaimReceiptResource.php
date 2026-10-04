<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Application\Claims\SubmittedClaim;

/**
 * What the website is told about a received claim (spec 020, FR-008): the id,
 * status, plan, amount and expiry. Never the address, reference or receipt.
 */
final class ClaimReceiptResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SubmittedClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'status' => $claim->status,
            'plan' => ['id' => $claim->planId, 'name' => $claim->planName],
            'amount' => ['amount_minor' => $claim->amountMinor, 'currency' => $claim->currency],
            'expires_at' => $claim->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}

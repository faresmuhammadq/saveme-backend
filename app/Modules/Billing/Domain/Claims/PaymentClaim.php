<?php

namespace App\Modules\Billing\Domain\Claims;

use App\Modules\Billing\Domain\RedeemCodes\RedeemCodeStatus;
use App\Modules\Billing\Domain\Refusals\ClaimNotReviewable;
use App\Modules\Billing\Domain\Refusals\CodeNotRevocable;
use DateTimeImmutable;

/**
 * The state rules of a Payment Claim (spec 020, FR-015, FR-016, FR-024).
 */
final class PaymentClaim
{
    /**
     * A claim is reviewed once, and only while it is submitted and unexpired,
     * whether or not the hourly sweep has run.
     *
     * @throws ClaimNotReviewable
     */
    public static function assertReviewable(ClaimRecord $claim, DateTimeImmutable $now): void
    {
        if ($claim->status !== ClaimStatus::Submitted || $claim->expiresAt <= $now) {
            throw new ClaimNotReviewable;
        }
    }

    /**
     * Only an issued, unredeemed code can be revoked.
     *
     * @throws CodeNotRevocable
     */
    public static function assertCodeRevocable(RedeemCodeStatus $codeStatus): void
    {
        if ($codeStatus !== RedeemCodeStatus::Issued) {
            throw new CodeNotRevocable;
        }
    }
}

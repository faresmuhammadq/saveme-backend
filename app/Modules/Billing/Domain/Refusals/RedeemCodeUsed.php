<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The code was redeemed by another account (spec 020, FR-026).
 */
final class RedeemCodeUsed extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::RedeemCodeUsed);
    }
}

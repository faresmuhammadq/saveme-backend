<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The code is past its 30 days (spec 020, FR-026).
 */
final class RedeemCodeExpired extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::RedeemCodeExpired);
    }
}

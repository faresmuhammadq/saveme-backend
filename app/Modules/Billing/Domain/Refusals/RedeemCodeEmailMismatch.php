<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The signed-in account's address is not the one the code was sent to; the address is never named (spec 020, FR-027).
 */
final class RedeemCodeEmailMismatch extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::RedeemCodeEmailMismatch);
    }
}

<?php

namespace App\Modules\Billing\Domain\GooglePlay\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * This Google Play purchase is linked to another account (spec 024, FR-008). Nothing about the other account is said.
 */
final class PurchaseAlreadyLinked extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::PurchaseAlreadyLinked);
    }
}

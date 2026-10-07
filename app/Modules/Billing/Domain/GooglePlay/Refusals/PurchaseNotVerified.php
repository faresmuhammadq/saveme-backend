<?php

namespace App\Modules\Billing\Domain\GooglePlay\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * Google does not confirm this purchase for this app and product (spec 024, FR-012).
 */
final class PurchaseNotVerified extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::PurchaseNotVerified);
    }
}

<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The wallet owner's plan does not grant the capability (P-09 rule 2).
 */
final class FeatureNotEntitled extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::FeatureNotEntitled);
    }
}

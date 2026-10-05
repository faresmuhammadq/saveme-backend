<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * Only an active, non-default plan can be retired (spec 021, FR-037).
 */
final class PlanNotRetirable extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::VersionConflict, detailKey: 'billing.refusals.plan_not_retirable');
    }
}

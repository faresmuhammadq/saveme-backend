<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The plan changed (or is gone) since the operator opened it (spec 021, FR-035).
 */
final class PlanChangedElsewhere extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::VersionConflict, detailKey: 'billing.refusals.plan_changed_elsewhere');
    }
}

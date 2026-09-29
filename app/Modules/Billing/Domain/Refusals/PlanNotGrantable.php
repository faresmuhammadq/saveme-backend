<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The plan cannot be granted: unknown, retired, or the default (free) plan.
 */
final class PlanNotGrantable extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [new FieldError('/plan_id', ErrorCode::InvalidEnumValue)]);
    }
}

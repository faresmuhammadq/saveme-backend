<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A grant or extension is between 1 and 3,650 days (spec 021, FR-026, FR-027).
 */
final class InvalidDays extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [new FieldError('/days', ErrorCode::OutOfRange)]);
    }
}

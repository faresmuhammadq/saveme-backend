<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * An operator action needs a reason of 1 to 500 characters, not blank.
 */
final class InvalidReason extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [new FieldError('/reason', ErrorCode::OutOfRange)]);
    }
}

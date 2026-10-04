<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The reject reason is not on the fixed list, or the internal note is over
 * 500 characters (spec 020, FR-018).
 */
final class InvalidRejectReason extends Refusal
{
    public function __construct(string $pointer = '/reason')
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [new FieldError($pointer, $pointer === '/reason' ? ErrorCode::InvalidEnumValue : ErrorCode::OutOfRange)]);
    }
}

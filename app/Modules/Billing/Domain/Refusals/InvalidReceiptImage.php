<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A screenshot failed a check (spec 020, FR-012). Carries the offending
 * member's pointer and field code; the bytes are never part of it.
 */
final class InvalidReceiptImage extends Refusal
{
    public function __construct(public readonly string $pointer, public readonly ErrorCode $fieldCode)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [new FieldError($pointer, $fieldCode)]);
    }
}

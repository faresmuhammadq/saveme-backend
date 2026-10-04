<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * What was typed is not 16 code characters (spec 020, FR-023). The request
 * validates first; this keeps the service safe when called without it.
 */
final class InvalidRedeemCode extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [new FieldError('/code', ErrorCode::InvalidType)]);
    }
}

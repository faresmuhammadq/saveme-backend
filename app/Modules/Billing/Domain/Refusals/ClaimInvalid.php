<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A claim that says something the service cannot accept: a plan that cannot
 * be claimed, a price that is not the plan's, a reference or address in no
 * valid form (spec 020, FR-004 to FR-007). The request validates first; this
 * keeps the service safe when called without it.
 */
final class ClaimInvalid extends Refusal
{
    /**
     * @param  list<FieldError>  $faults
     */
    public function __construct(array $faults)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: $faults);
    }
}

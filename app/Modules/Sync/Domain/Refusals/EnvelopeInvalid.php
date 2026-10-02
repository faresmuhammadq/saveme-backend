<?php

namespace App\Modules\Sync\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The batch envelope is unusable, so nothing is applied (spec 013, R-4).
 */
final class EnvelopeInvalid extends Refusal
{
    /**
     * @param  list<FieldError>  $fieldErrors
     */
    public function __construct(array $fieldErrors)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: $fieldErrors);
    }
}

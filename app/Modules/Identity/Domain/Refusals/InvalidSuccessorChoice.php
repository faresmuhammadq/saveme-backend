<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A chosen new owner does not fit the wallet or the member (spec 022,
 * FR-035). Reported as VALIDATION_FAILED at the entry's pointer; nothing
 * changes.
 */
final class InvalidSuccessorChoice extends Refusal
{
    /**
     * @param  list<FieldError>  $errors
     */
    public function __construct(array $errors)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: $errors);
    }
}

<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A new password breaks the policy (FR-006) or repeats the current one.
 * Reported as a VALIDATION_FAILED on the member that carried it.
 */
final class InvalidPassword extends Refusal
{
    public function __construct(string $member)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [
            new FieldError('/'.$member, ErrorCode::OutOfRange),
        ]);
    }
}

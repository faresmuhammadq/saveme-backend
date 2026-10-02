<?php

namespace App\Modules\Sharing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * An invitation write the stored state refuses: an immutable member changed
 * (spec 016, section 6).
 */
final class InvitationStateInvalid extends Refusal
{
    /**
     * @param  list<FieldError>  $fieldErrors
     */
    public function __construct(array $fieldErrors)
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: $fieldErrors);
    }
}

<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The verification link is expired, used, superseded or unknown.
 */
final class VerificationLinkInvalid extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::VerificationTokenInvalid);
    }
}

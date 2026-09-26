<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The reset link is expired, used, superseded or unknown.
 */
final class ResetLinkInvalid extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::ResetTokenInvalid);
    }
}

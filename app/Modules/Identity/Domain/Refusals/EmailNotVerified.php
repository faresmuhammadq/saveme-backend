<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The caller's address is not verified, so wallet writes are refused (security baseline, Verification gate).
 */
final class EmailNotVerified extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::EmailNotVerified);
    }
}

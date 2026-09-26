<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * Registration for an address that already has an account. Registration is
 * the one surface that says so (owner decision 2026-09-27, spec 007); sign-in
 * and password reset still answer uniformly.
 */
final class EmailAlreadyRegistered extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::EmailAlreadyRegistered);
    }
}

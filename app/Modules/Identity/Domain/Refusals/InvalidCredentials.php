<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The address or the password is wrong, answered identically whether or not the address is registered.
 */
final class InvalidCredentials extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::InvalidCredentials);
    }
}

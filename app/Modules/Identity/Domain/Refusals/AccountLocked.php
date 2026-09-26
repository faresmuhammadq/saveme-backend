<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * Too many consecutive failures against this address; the lock is temporary
 * and caps at 60 minutes (security baseline, Lockout).
 */
final class AccountLocked extends Refusal
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(ErrorCode::AccountLocked, retryAfterSeconds: max(1, $retryAfterSeconds));
    }
}

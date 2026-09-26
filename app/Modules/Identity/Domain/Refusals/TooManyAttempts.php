<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * An origin or an address spent its allowance (security baseline, Lockout
 * per-origin limit; the route budgets).
 */
final class TooManyAttempts extends Refusal
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(ErrorCode::RateLimited, retryAfterSeconds: max(1, $retryAfterSeconds));
    }
}

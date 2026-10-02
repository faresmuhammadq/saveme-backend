<?php

namespace App\Modules\Sharing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A token that works for this caller in no way: unknown, used, revoked,
 * declined, expired, of a deleted wallet, or an email invitation addressed to
 * someone else. One answer for every case, so a token reveals nothing about
 * whom it was for (spec 016, FR-010).
 */
final class TokenNotLive extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::NotFound);
    }
}

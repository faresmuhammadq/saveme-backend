<?php

namespace App\Modules\Notifications\Domain\Registration;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The id is not the caller's own current device session: another person's,
 * another of the caller's own, ended or unknown. All answer 404, so nothing
 * about other sessions is disclosed (spec 023, FR-001).
 */
final class SessionNotCurrent extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::NotFound);
    }
}

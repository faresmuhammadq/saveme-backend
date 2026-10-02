<?php

namespace App\Modules\Budgeting\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A progress cursor sent with a `date` in another Budget Period than the one
 * the walk began in (spec 015, FR-016, A-28).
 */
final class ProgressCursorMismatch extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::InvalidCursor);
    }
}

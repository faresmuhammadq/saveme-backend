<?php

namespace App\Modules\Sync\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;
use App\Modules\Sync\Domain\Cursor\CursorRefusal;

/**
 * The cursor is not one this feed issued to this reader (spec 013, R-9).
 */
final class CursorInvalid extends Refusal
{
    public function __construct(public readonly CursorRefusal $reason)
    {
        parent::__construct(ErrorCode::InvalidCursor);
    }
}

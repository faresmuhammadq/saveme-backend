<?php

namespace App\Modules\Sync\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The cursor is behind the scope's purge horizon: a tombstone the client has
 * not seen was purged, so it must read the feed again from the start (spec
 * 013, R-10).
 */
final class CursorExpired extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::SyncCursorExpired);
    }
}

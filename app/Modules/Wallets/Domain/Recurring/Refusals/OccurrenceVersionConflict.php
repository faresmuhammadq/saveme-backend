<?php

namespace App\Modules\Wallets\Domain\Recurring\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A write carried a stale version, or targeted a tombstone. Nothing was
 * applied; `current` carries the stored occurrence as a read renders it, so
 * the client of a lost confirmation race sees the confirmed occurrence.
 */
final class OccurrenceVersionConflict extends Refusal
{
    /**
     * @param  array<string, mixed>  $current  the occurrence as a read returns it
     */
    public function __construct(array $current)
    {
        parent::__construct(ErrorCode::VersionConflict, extensions: ['current' => $current]);
    }
}

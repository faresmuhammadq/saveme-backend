<?php

namespace App\Modules\Identity\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A profile save carried a stale version. Nothing was applied; `current`
 * carries the stored profile so the client merges without a second request
 * (P-07 rules 2–3).
 */
final class ProfileVersionConflict extends Refusal
{
    /**
     * @param  array<string, mixed>  $current  the profile as a read returns it
     */
    public function __construct(array $current)
    {
        parent::__construct(ErrorCode::VersionConflict, extensions: ['current' => $current]);
    }
}

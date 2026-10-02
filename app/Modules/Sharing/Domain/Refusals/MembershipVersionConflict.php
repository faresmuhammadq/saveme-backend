<?php

namespace App\Modules\Sharing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A membership write carried a stale version. Nothing was applied; `current`
 * carries the stored membership as the member list renders it (P-07).
 */
final class MembershipVersionConflict extends Refusal
{
    /**
     * @param  array<string, mixed>  $current  the membership as a read returns it
     */
    public function __construct(array $current)
    {
        parent::__construct(ErrorCode::VersionConflict, extensions: ['current' => $current]);
    }
}

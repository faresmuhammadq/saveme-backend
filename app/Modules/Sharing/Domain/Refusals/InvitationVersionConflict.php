<?php

namespace App\Modules\Sharing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A write carried a stale version, or targeted a closed invitation. Nothing
 * was applied; `current` carries the stored invitation as a read renders it
 * (P-07).
 */
final class InvitationVersionConflict extends Refusal
{
    /**
     * @param  array<string, mixed>  $current  the invitation as a read returns it
     */
    public function __construct(array $current)
    {
        parent::__construct(ErrorCode::VersionConflict, extensions: ['current' => $current]);
    }
}

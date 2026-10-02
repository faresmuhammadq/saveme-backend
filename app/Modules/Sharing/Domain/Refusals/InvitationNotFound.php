<?php

namespace App\Modules\Sharing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * Not an invitation of this wallet, or the id belongs to another kind of
 * record (P-06 rule 3, P-08 rule 3): not-found, never a hint it exists.
 */
final class InvitationNotFound extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::NotFound);
    }
}

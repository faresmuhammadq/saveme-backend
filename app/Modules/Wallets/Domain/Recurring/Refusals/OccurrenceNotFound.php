<?php

namespace App\Modules\Wallets\Domain\Recurring\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The occurrence is not one the caller may know of: unknown (a client never
 * creates one, ADR-0018 rule 1), another wallet's, another kind of record,
 * or the wallet is not theirs.
 */
final class OccurrenceNotFound extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::NotFound);
    }
}

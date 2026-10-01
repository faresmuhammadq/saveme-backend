<?php

namespace App\Modules\Wallets\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A member whose role does not permit the action (permission matrix, spec 010 section 6).
 */
final class WalletActionForbidden extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::Forbidden);
    }
}

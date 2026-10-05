<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The person's account is not active (waiting for deletion, or deleted), so an operator cannot grant or extend access (spec 021, FR-029).
 */
final class AccountNotActive extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::VersionConflict, detailKey: 'billing.refusals.account_not_active');
    }
}

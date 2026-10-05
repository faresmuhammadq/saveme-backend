<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * No field of the plan edit differs from the stored plan (spec 021, FR-038).
 */
final class NothingToSave extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::ValidationFailed, detailKey: 'billing.refusals.nothing_to_save');
    }
}

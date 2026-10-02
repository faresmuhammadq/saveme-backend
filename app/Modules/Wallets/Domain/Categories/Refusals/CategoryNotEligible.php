<?php

namespace App\Modules\Wallets\Domain\Categories\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A category a budget may not name (spec 015, FR-003): unknown, another
 * person's, deleted or an income category. One answer for all, so nothing
 * hints at which. The caller maps it onto its own field pointer.
 */
final class CategoryNotEligible extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::InvalidEnumValue);
    }
}

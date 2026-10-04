<?php

namespace App\Modules\Reporting\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * An explicit Report Range the rules refuse: out of order, over 366 days, or
 * outside 1900-01-01 to 2999-12-31 (spec 019, FR-003, FR-019). A query
 * problem, so it answers 400 INVALID_QUERY_PARAMETER like every other.
 */
final class InvalidReportRange extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::InvalidQueryParameter);
    }
}

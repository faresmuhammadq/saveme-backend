<?php

namespace App\Modules\Billing\Domain\GooglePlay\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * Google could not be reached: nothing is granted, retry with the same key
 * (spec 024, FR-015).
 */
final class PlayUnavailable extends Refusal
{
    public function __construct(int $retryAfterSeconds = 60)
    {
        parent::__construct(ErrorCode::ServiceUnavailable, $retryAfterSeconds);
    }
}

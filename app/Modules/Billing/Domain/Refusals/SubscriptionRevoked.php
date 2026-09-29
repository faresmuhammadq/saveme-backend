<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A revoked subscription is final (ADR-0013 rule 4): it cannot be extended.
 */
final class SubscriptionRevoked extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::ValidationFailed, fieldErrors: [new FieldError('/status', ErrorCode::ImmutableField)]);
    }
}

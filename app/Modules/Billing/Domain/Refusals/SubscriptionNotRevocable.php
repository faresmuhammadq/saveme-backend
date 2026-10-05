<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A Google Play subscription is managed by Google Play and is never revoked by an operator (spec 021, FR-029).
 */
final class SubscriptionNotRevocable extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::VersionConflict, detailKey: 'billing.refusals.subscription_not_revocable');
    }
}

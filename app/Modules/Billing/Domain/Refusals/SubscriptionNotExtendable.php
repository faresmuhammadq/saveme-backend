<?php

namespace App\Modules\Billing\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * Only a manual subscription with an end date can be extended by an operator; Google Play and InstaPay periods are not (spec 021, FR-029; ADR-0013 rule 3).
 */
final class SubscriptionNotExtendable extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::VersionConflict, detailKey: 'billing.refusals.subscription_not_extendable');
    }
}

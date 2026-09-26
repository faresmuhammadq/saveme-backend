<?php

namespace App\Modules\Billing\Application\Provisional;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Application\Entitlements\EntitlementSummary;
use App\Modules\Billing\Application\SubscriptionService;

/**
 * Stand-in for Billing until B3 builds the plan catalogue (spec 007, R-11).
 * Enrolling stores nothing; the summary is the Free plan, which in MVP is
 * limited only on sharing (P-09 rule 5).
 *
 * Replaced by B3; delete this directory then.
 */
final class ProvisionalFreePlan implements EntitlementService, SubscriptionService
{
    public function enrolInDefaultPlan(string $userId): void {}

    public function summaryFor(string $userId): EntitlementSummary
    {
        return new EntitlementSummary('billing.plans.free', ['sharing.enabled' => false]);
    }
}

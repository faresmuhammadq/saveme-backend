<?php

namespace App\Modules\Billing\Application\Entitlements;

/**
 * Every capability question goes through this service (P-09 rule 2). B3 adds
 * `can()` and `quota()` against the Feature enum; B1 needs only the summary a
 * sign-in reports.
 */
interface EntitlementService
{
    public function summaryFor(string $userId): EntitlementSummary;
}

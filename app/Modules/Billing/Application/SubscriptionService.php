<?php

namespace App\Modules\Billing\Application;

/**
 * Billing's subscription entry point for other modules. Identity calls it
 * inside the registration transaction, so an account never exists without its
 * default subscription (spec 007, R-11).
 */
interface SubscriptionService
{
    public function enrolInDefaultPlan(string $userId): void;
}

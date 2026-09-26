<?php

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Application\Provisional\ProvisionalFreePlan;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Billing's application services. Until B3, both resolve to the
 * provisional Free plan.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProvisionalFreePlan::class);
        $this->app->bind(SubscriptionService::class, ProvisionalFreePlan::class);
        $this->app->bind(EntitlementService::class, ProvisionalFreePlan::class);
    }
}

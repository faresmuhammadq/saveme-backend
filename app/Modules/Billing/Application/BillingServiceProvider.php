<?php

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Billing's application services. The entitlement service is scoped:
 * fresh for every request or job, so an answer never outlives it (ADR-0013 rule 8).
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(EntitlementService::class);
    }
}

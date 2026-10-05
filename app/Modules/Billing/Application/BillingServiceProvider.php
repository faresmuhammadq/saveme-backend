<?php

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Application\Departure\EndSubscriptions;
use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Application\Export\SubscriptionSection;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Binds Billing's application services. The entitlement service is scoped:
 * fresh for every request or job, so an answer never outlives it (ADR-0013 rule 8).
 *
 * At boot it refuses a mail transport that writes messages where logs go
 * (`log`, `array`) outside local development and tests: the approval mail
 * carries a redeem code (spec 020, FR-040, A-34).
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(EntitlementService::class);

        // Identity orchestrates exports and purges; Billing supplies its share (spec 022, P-02).
        $this->app->tag([SubscriptionSection::class], 'identity.export-sections');
        $this->app->tag([EndSubscriptions::class], 'identity.purge-participants');
    }

    public function boot(): void
    {
        if (in_array(config('mail.default'), ['log', 'array'], true) && ! $this->app->environment(['local', 'testing'])) {
            throw new RuntimeException('The "log" and "array" mail transports are refused outside local and testing: the approval mail carries a redeem code.');
        }
    }
}

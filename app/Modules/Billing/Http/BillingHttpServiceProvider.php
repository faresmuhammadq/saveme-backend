<?php

namespace App\Modules\Billing\Http;

use App\Modules\Billing\Http\Console\ApproveClaim;
use App\Modules\Billing\Http\Console\ExpireClaims;
use App\Modules\Billing\Http\Console\ExpireRedeemCodes;
use App\Modules\Billing\Http\Console\LapseSubscriptions;
use App\Modules\Billing\Http\Console\ListClaims;
use App\Modules\Billing\Http\Console\PurgeClaims;
use App\Modules\Billing\Http\Console\PurgeIdempotencyRecords;
use App\Modules\Billing\Http\Console\ReconcilePlay;
use App\Modules\Billing\Http\Console\RejectClaim;
use App\Modules\Billing\Http\Console\RevokeClaimCode;
use App\Modules\Billing\Http\Console\SendRenewalReminders;
use App\Modules\Billing\Http\Console\SweepPlay;
use App\Modules\Billing\Http\Middleware\ClaimsAreWebOnly;
use App\Modules\Billing\Http\Middleware\Idempotent;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Billing's HTTP surface under /api/v1, its mail views, its middleware aliases
 * and its console commands.
 */
class BillingHttpServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'billing');
        Blade::anonymousComponentPath(__DIR__.'/Views/components', 'billing');

        $router->aliasMiddleware('billing.idempotent', Idempotent::class);
        $router->aliasMiddleware('billing.web-only', ClaimsAreWebOnly::class);

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                LapseSubscriptions::class,
                PurgeIdempotencyRecords::class,
                ListClaims::class,
                ApproveClaim::class,
                RejectClaim::class,
                RevokeClaimCode::class,
                ExpireClaims::class,
                ExpireRedeemCodes::class,
                SendRenewalReminders::class,
                PurgeClaims::class,
                ReconcilePlay::class,
                SweepPlay::class,
            ]);
        }
    }
}

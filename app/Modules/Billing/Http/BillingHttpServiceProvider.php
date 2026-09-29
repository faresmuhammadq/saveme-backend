<?php

namespace App\Modules\Billing\Http;

use App\Modules\Billing\Http\Console\LapseSubscriptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Billing's HTTP surface under /api/v1 and its console command.
 */
class BillingHttpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([LapseSubscriptions::class]);
        }
    }
}

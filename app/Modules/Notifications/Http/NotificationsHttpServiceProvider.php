<?php

namespace App\Modules\Notifications\Http;

use App\Modules\Notifications\Http\Console\OccurrenceRemindersCommand;
use App\Modules\Notifications\Http\Console\PruneCommand;
use App\Modules\Notifications\Http\Console\ReleaseHeldCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Notifications' HTTP surface under /api/v1, its push translations, its route
 * budgets and its console commands (spec 023).
 */
class NotificationsHttpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'notifications');

        $this->defineRouteBudgets();

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                OccurrenceRemindersCommand::class,
                ReleaseHeldCommand::class,
                PruneCommand::class,
            ]);
        }
    }

    /**
     * Security baseline, route budgets: device registration writes 20 per hour
     * per session (PUT and DELETE share it), preference saves 30 per hour per
     * person. A spent budget is rendered as 429 RATE_LIMITED by the runtime.
     */
    private function defineRouteBudgets(): void
    {
        RateLimiter::for('notifications-devices', fn (Request $request): Limit => Limit::perHour(20)
            ->by('notifications-devices:'.($request->user('api')?->currentAccessToken()?->getKey() ?? $request->ip())));

        RateLimiter::for('notifications-preferences', fn (Request $request): Limit => Limit::perHour(30)
            ->by('notifications-preferences:'.($request->user('api')?->getAuthIdentifier() ?? $request->ip())));
    }
}

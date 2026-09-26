<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Http\Console\ExpireSessions;
use App\Modules\Identity\Http\Console\TimingProbe;
use App\Modules\Identity\Http\Middleware\EnsureAddressVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Identity's HTTP surface: routes under /api/v1, mail views and strings, the
 * route budgets, and the verification gate.
 *
 * Other modules gate wallet writes with the middleware alias
 * `address.verified` — a string, so they never import this namespace (P-02
 * rule 3; spec 007, R-17).
 */
class IdentityServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'identity');
        Blade::anonymousComponentPath(__DIR__.'/Views/components', 'identity');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'identity');

        $router->aliasMiddleware('address.verified', EnsureAddressVerified::class);

        $this->defineRouteBudgets();

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands($this->app->environment('local') ? [ExpireSessions::class, TimingProbe::class] : [ExpireSessions::class]);
        }
    }

    /**
     * Route budgets from the security baseline. A spent budget is rendered as
     * 429 RATE_LIMITED with Retry-After and RateLimit-* by the runtime.
     */
    private function defineRouteBudgets(): void
    {
        RateLimiter::for('identity-register', fn (Request $request): Limit => Limit::perHour((int) config('identity.throttle.register_per_hour_per_origin'))
            ->by('register:'.$request->ip()));

        RateLimiter::for('identity-resend', fn (Request $request): Limit => Limit::perHour((int) config('identity.throttle.resend_per_hour_per_address'))
            ->by('resend:'.$request->user()?->getAuthIdentifier()));

        // Counted per address lookup value, registered or not, so the budget
        // itself discloses nothing about registration.
        RateLimiter::for('identity-reset', fn (Request $request): Limit => Limit::perHour((int) config('identity.throttle.reset_per_hour_per_address'))
            ->by('reset:'.app(AddressLookup::class)->for((string) $request->input('email', ''))));
    }
}

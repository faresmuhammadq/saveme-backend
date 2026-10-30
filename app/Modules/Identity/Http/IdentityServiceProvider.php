<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\Deletion\PurgeAccount;
use App\Modules\Identity\Application\Export\GenerateExport;
use App\Modules\Identity\Application\Export\IdentitySections;
use App\Modules\Identity\Application\Sync\FeedPresenter;
use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Http\Console\ExpireExports;
use App\Modules\Identity\Http\Console\ExpireSessions;
use App\Modules\Identity\Http\Console\PurgeAccounts;
use App\Modules\Identity\Http\Console\SendDeletionReminders;
use App\Modules\Identity\Http\Console\TimingProbe;
use App\Modules\Identity\Http\Controllers\ExportFileController;
use App\Modules\Identity\Http\Controllers\ResetPageController;
use App\Modules\Identity\Http\Controllers\VerificationPageController;
use App\Modules\Identity\Http\Listeners\ApplyProfileLanguage;
use App\Modules\Identity\Http\Middleware\EnsureAddressVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;

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
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        FeedPresenter::class => ResourceFeedPresenter::class,
    ];

    /**
     * The tags other modules attach their export sections and purge steps to
     * (spec 022, R-4, R-12): Identity orchestrates, each module owns its own.
     */
    public const EXPORT_SECTIONS = 'identity.export-sections';

    public const PURGE_PARTICIPANTS = 'identity.purge-participants';

    public function register(): void
    {
        $this->app->tag([IdentitySections::class], self::EXPORT_SECTIONS);

        $this->app->when(PurgeAccount::class)
            ->needs('$participants')
            ->giveTagged(self::PURGE_PARTICIPANTS);

        $this->app->when(GenerateExport::class)
            ->needs('$sections')
            ->giveTagged(self::EXPORT_SECTIONS);
    }

    public function boot(Router $router): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'identity');
        Blade::anonymousComponentPath(__DIR__.'/Views/components', 'identity');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'identity');

        $router->aliasMiddleware('address.verified', EnsureAddressVerified::class);

        $this->defineRouteBudgets();

        Event::listen(TokenAuthenticated::class, ApplyProfileLanguage::class);

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');

        // The signed archive link a browser or share sheet opens without bearer or client headers
        // (spec 022, R-6). Not an API operation; the signature is checked in the controller so a bad
        // one answers 404, not 403.
        Route::get('exports/{export}/file', ExportFileController::class)
            ->whereUuid('export')
            ->middleware('throttle:identity-export-file')
            ->name('identity.exports.file');

        // The mailed verification link: a browser GET that redeems the token and returns the person
        // to the app. A missing or malformed token shows the invalid page.
        Route::middleware('web')->get('verify-email', VerificationPageController::class)
            ->middleware('throttle:identity-verify-page')
            ->name('identity.verify-email.page');

        // The mailed reset link: a browser GET that hands the token to the app. The password is
        // chosen in the app, so nothing is redeemed here.
        Route::middleware('web')->get('reset-password', ResetPageController::class)
            ->middleware('throttle:identity-verify-page')
            ->name('identity.reset-password.page');

        if ($this->app->runningInConsole()) {
            $this->commands($this->app->environment('local') ? [ExpireSessions::class, ExpireExports::class, PurgeAccounts::class, SendDeletionReminders::class, TimingProbe::class] : [ExpireSessions::class, ExpireExports::class, PurgeAccounts::class, SendDeletionReminders::class]);
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

        RateLimiter::for('identity-verify-page', fn (Request $request): Limit => Limit::perMinute(20)->by('verify-page:'.$request->ip()));

        // The export archive link: 20 per 10 minutes per origin (spec 022, FR-018).
        RateLimiter::for('identity-export-file', fn (Request $request): Limit => Limit::perSecond(
            (int) config('identity.export_file_budget.attempts'),
            (int) config('identity.export_file_budget.decay_seconds'),
        )->by('export-file:'.$request->ip()));

        // Counted per address lookup value, registered or not, so the budget
        // itself discloses nothing about registration.
        RateLimiter::for('identity-reset', fn (Request $request): Limit => Limit::perHour((int) config('identity.throttle.reset_per_hour_per_address'))
            ->by('reset:'.app(AddressLookup::class)->for((string) $request->input('email', ''))));
    }
}

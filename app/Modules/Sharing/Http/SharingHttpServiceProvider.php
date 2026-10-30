<?php

namespace App\Modules\Sharing\Http;

use App\Modules\Sharing\Application\Sync\FeedPresenter;
use App\Modules\Sharing\Http\Console\ExpireInvitations;
use App\Modules\Sharing\Http\Controllers\InvitationPageController;
use App\Modules\Sharing\Http\Middleware\EnforceInviteBudget;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Sharing's HTTP surface under /api/v1, its mail views and strings, the
 * invite-budget middleware alias and its console command.
 */
class SharingHttpServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        FeedPresenter::class => InvitationFeedPresenter::class,
    ];

    public function boot(Router $router): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'sharing');
        Blade::anonymousComponentPath(__DIR__.'/Views/components', 'sharing');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'sharing');

        $router->aliasMiddleware('sharing.invite-budget', EnforceInviteBudget::class);

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');

        // The mailed invitation link: a browser GET that hands the token to the app, where it is
        // previewed and accepted. Nothing is spent here.
        Route::middleware(['web', 'throttle:30,1'])->get('invite', InvitationPageController::class)
            ->name('sharing.invite.page');

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireInvitations::class]);
        }
    }
}

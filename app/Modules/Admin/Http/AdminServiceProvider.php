<?php

namespace App\Modules\Admin\Http;

use App\Modules\Admin\Application\Listeners\NotifyOperatorsOfDeletionCancelledBySignIn;
use App\Modules\Admin\Http\Icons\IconRegistry;
use App\Modules\Admin\Http\Middleware\ResolveAdminLocale;
use App\Modules\Admin\Http\Navigation\NavigationRegistry;
use App\Modules\Admin\Http\Presenters\IdentifierPresenter;
use App\Modules\Admin\Http\Presenters\MoneyPresenter;
use App\Modules\Admin\Http\Presenters\TemporalPresenter;
use App\Modules\Identity\Domain\Events\UserDeletionCancelled;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Registers the back office from inside the Admin module (constitution P-18
 * rule 3): the `admin::` view and translation namespaces, the `<x-admin::…>`
 * primitives, the `admin` Livewire namespace and the back-office routes.
 */
class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IconRegistry::class);
        $this->app->singleton(MoneyPresenter::class);
        $this->app->singleton(TemporalPresenter::class);
        $this->app->singleton(IdentifierPresenter::class);
        $this->app->singleton(NavigationRegistry::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'admin');
        Blade::anonymousComponentPath(__DIR__.'/Views/components', 'admin');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'admin');

        Livewire::addNamespace(
            'admin',
            classNamespace: 'App\\Modules\\Admin\\Http\\Livewire',
            classPath: __DIR__.'/Livewire',
            classViewPath: __DIR__.'/Views/livewire',
        );

        Livewire::addPersistentMiddleware([ResolveAdminLocale::class]);

        // Operators hear when a sign-in cancels a deletion (spec 007, R-14).
        // An Identity domain event is the cross-module mechanism P-02 allows.
        Event::listen(UserDeletionCancelled::class, NotifyOperatorsOfDeletionCancelledBySignIn::class);

        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}

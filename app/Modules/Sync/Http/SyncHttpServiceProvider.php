<?php

namespace App\Modules\Sync\Http;

use App\Modules\Sync\Domain\Ports\ItemDispatcher;
use App\Modules\Sync\Http\Console\PurgeTombstones;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Sync's HTTP surface under /api/v1, its item dispatcher and its console
 * command.
 */
class SyncHttpServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ItemDispatcher::class => RouterItemDispatcher::class,
    ];

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeTombstones::class]);
        }
    }
}

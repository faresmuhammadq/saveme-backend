<?php

namespace App\Modules\Wallets\Http;

use App\Modules\Wallets\Application\Sync\FeedPresenter;
use App\Modules\Wallets\Http\Console\VerifyBalances;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wallets' HTTP surface under /api/v1 and its console command.
 */
class WalletsHttpServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        FeedPresenter::class => ResourceFeedPresenter::class,
    ];

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyBalances::class]);
        }
    }
}

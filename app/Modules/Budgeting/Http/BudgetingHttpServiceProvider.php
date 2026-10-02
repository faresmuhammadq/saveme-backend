<?php

namespace App\Modules\Budgeting\Http;

use App\Modules\Budgeting\Application\Sync\FeedPresenter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Budgeting's HTTP surface under /api/v1.
 */
class BudgetingHttpServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        FeedPresenter::class => BudgetFeedPresenter::class,
    ];

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}

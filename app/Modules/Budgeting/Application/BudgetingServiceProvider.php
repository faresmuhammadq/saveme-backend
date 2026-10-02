<?php

namespace App\Modules\Budgeting\Application;

use App\Modules\Wallets\Application\Categories\CategoryUsage;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Budgeting's category usage participant with Wallets (spec 015,
 * FR-018): tagged with the interface's name, so "in use" counts budgets.
 */
class BudgetingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([BudgetCategoryUsage::class], CategoryUsage::class);
    }
}

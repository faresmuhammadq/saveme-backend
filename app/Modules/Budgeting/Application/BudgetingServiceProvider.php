<?php

namespace App\Modules\Budgeting\Application;

use App\Modules\Budgeting\Application\Departure\DeleteBudgets;
use App\Modules\Budgeting\Application\Export\BudgetSection;
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

        // Identity orchestrates exports and purges; Budgeting supplies its share (spec 022, P-02).
        $this->app->tag([BudgetSection::class], 'identity.export-sections');
        $this->app->tag([DeleteBudgets::class], 'identity.purge-participants');
    }
}

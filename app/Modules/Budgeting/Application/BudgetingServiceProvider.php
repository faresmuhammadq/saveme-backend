<?php

namespace App\Modules\Budgeting\Application;

use App\Modules\Budgeting\Application\Departure\DeleteBudgets;
use App\Modules\Budgeting\Application\Export\BudgetSection;
use App\Modules\Budgeting\Application\Listeners\EvaluateBudgetsAfterPosting;
use App\Modules\Wallets\Application\Categories\CategoryUsage;
use App\Modules\Wallets\Domain\Events\TransactionPosted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Budgeting's category usage participant with Wallets (spec 015,
 * FR-018): tagged with the interface's name, so "in use" counts budgets, and
 * subscribes the overrun detection to posted expenses (spec 023).
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

    public function boot(): void
    {
        // A posted expense may take a budget over its limit (spec 023, FR-026): queued and idempotent (P-11).
        Event::listen(TransactionPosted::class, EvaluateBudgetsAfterPosting::class);
    }
}

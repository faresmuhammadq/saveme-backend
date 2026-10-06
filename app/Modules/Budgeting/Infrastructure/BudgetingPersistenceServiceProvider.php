<?php

namespace App\Modules\Budgeting\Infrastructure;

use App\Modules\Budgeting\Domain\Ports\BudgetRepository;
use App\Modules\Budgeting\Domain\Ports\OverrunRepository;
use App\Modules\Budgeting\Infrastructure\Repositories\EloquentBudgetRepository;
use App\Modules\Budgeting\Infrastructure\Repositories\EloquentOverrunRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Budgeting's Domain ports to their persistence (P-02). Migrations and
 * factories are found by ModuleServiceProvider.
 */
class BudgetingPersistenceServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        BudgetRepository::class => EloquentBudgetRepository::class,
        OverrunRepository::class => EloquentOverrunRepository::class,
    ];
}

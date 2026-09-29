<?php

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Infrastructure\Repositories\EloquentPlanRepository;
use App\Modules\Billing\Infrastructure\Repositories\EloquentSubscriptionRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Billing's Domain ports to their persistence (P-02).
 */
class BillingPersistenceServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        PlanRepository::class => EloquentPlanRepository::class,
        SubscriptionRepository::class => EloquentSubscriptionRepository::class,
    ];
}

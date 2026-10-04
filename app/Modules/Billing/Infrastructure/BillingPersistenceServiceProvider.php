<?php

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\ClaimSealer;
use App\Modules\Billing\Domain\Ports\IdempotencyStore;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\ReceiptStore;
use App\Modules\Billing\Domain\Ports\RedeemCodeRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Infrastructure\Crypto\BillingKeyring;
use App\Modules\Billing\Infrastructure\Crypto\BillingSealer;
use App\Modules\Billing\Infrastructure\Repositories\EloquentClaimRepository;
use App\Modules\Billing\Infrastructure\Repositories\EloquentIdempotencyStore;
use App\Modules\Billing\Infrastructure\Repositories\EloquentPlanRepository;
use App\Modules\Billing\Infrastructure\Repositories\EloquentRedeemCodeRepository;
use App\Modules\Billing\Infrastructure\Repositories\EloquentSubscriptionRepository;
use App\Modules\Billing\Infrastructure\Repositories\SealedReceiptStore;
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
        ClaimRepository::class => EloquentClaimRepository::class,
        RedeemCodeRepository::class => EloquentRedeemCodeRepository::class,
        IdempotencyStore::class => EloquentIdempotencyStore::class,
        ReceiptStore::class => SealedReceiptStore::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ClaimSealer::class, fn (): ClaimSealer => new BillingSealer(BillingKeyring::fromConfig()));
    }
}

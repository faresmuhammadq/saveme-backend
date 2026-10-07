<?php

namespace App\Modules\Billing\Infrastructure;

use App\Modules\Billing\Domain\GooglePlay\PlayBillingGateway;
use App\Modules\Billing\Domain\Ports\ClaimRepository;
use App\Modules\Billing\Domain\Ports\ClaimSealer;
use App\Modules\Billing\Domain\Ports\IdempotencyStore;
use App\Modules\Billing\Domain\Ports\PlanRepository;
use App\Modules\Billing\Domain\Ports\PlayAccountReferences;
use App\Modules\Billing\Domain\Ports\PlayNotificationRepository;
use App\Modules\Billing\Domain\Ports\PlayPurchaseRepository;
use App\Modules\Billing\Domain\Ports\PlayTokenSealer;
use App\Modules\Billing\Domain\Ports\ReceiptStore;
use App\Modules\Billing\Domain\Ports\RedeemCodeRepository;
use App\Modules\Billing\Domain\Ports\SubscriptionRepository;
use App\Modules\Billing\Domain\Ports\TokenLookup;
use App\Modules\Billing\Infrastructure\Crypto\BillingKeyring;
use App\Modules\Billing\Infrastructure\Crypto\BillingSealer;
use App\Modules\Billing\Infrastructure\Crypto\HmacTokenLookup;
use App\Modules\Billing\Infrastructure\Crypto\PlaySealer;
use App\Modules\Billing\Infrastructure\GooglePlay\PlayGatewayFactory;
use App\Modules\Billing\Infrastructure\Repositories\EloquentClaimRepository;
use App\Modules\Billing\Infrastructure\Repositories\EloquentIdempotencyStore;
use App\Modules\Billing\Infrastructure\Repositories\EloquentPlanRepository;
use App\Modules\Billing\Infrastructure\Repositories\EloquentPlayAccountReferences;
use App\Modules\Billing\Infrastructure\Repositories\EloquentPlayNotificationRepository;
use App\Modules\Billing\Infrastructure\Repositories\EloquentPlayPurchaseRepository;
use App\Modules\Billing\Infrastructure\Repositories\EloquentRedeemCodeRepository;
use App\Modules\Billing\Infrastructure\Repositories\EloquentSubscriptionRepository;
use App\Modules\Billing\Infrastructure\Repositories\SealedReceiptStore;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Billing's Domain ports to their persistence, crypto and Google Play
 * adapters (P-02).
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
        PlayPurchaseRepository::class => EloquentPlayPurchaseRepository::class,
        PlayNotificationRepository::class => EloquentPlayNotificationRepository::class,
        PlayAccountReferences::class => EloquentPlayAccountReferences::class,
        TokenLookup::class => HmacTokenLookup::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ClaimSealer::class, fn (): ClaimSealer => new BillingSealer(BillingKeyring::fromConfig()));
        $this->app->singleton(PlayTokenSealer::class, fn (): PlayTokenSealer => new PlaySealer(BillingKeyring::fromConfig()));
        $this->app->singleton(PlayBillingGateway::class, fn (): PlayBillingGateway => PlayGatewayFactory::make((string) config('billing.google_play.driver')));
    }

    /**
     * The Google Play gateway follows `billing.google_play.driver`; at boot
     * `fake` is refused outside local and testing, and `google` without its
     * credentials, package, push audience and keys (spec 024, FR-058).
     */
    public function boot(): void
    {
        PlayGatewayFactory::assertBootable((string) config('billing.google_play.driver'), $this->app->environment());
    }
}

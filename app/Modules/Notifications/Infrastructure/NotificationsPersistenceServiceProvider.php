<?php

namespace App\Modules\Notifications\Infrastructure;

use App\Modules\Notifications\Domain\Delivery\PushGateway;
use App\Modules\Notifications\Domain\Ports\DeliveryRepository;
use App\Modules\Notifications\Domain\Ports\NotificationRepository;
use App\Modules\Notifications\Domain\Ports\PreferencesRepository;
use App\Modules\Notifications\Domain\Ports\RegistrationRepository;
use App\Modules\Notifications\Domain\Ports\TokenLookup;
use App\Modules\Notifications\Domain\Ports\TokenSealer;
use App\Modules\Notifications\Infrastructure\Crypto\HmacTokenLookup;
use App\Modules\Notifications\Infrastructure\Crypto\NotificationsKeyring;
use App\Modules\Notifications\Infrastructure\Crypto\NotificationsSealer;
use App\Modules\Notifications\Infrastructure\Push\PushGatewayFactory;
use App\Modules\Notifications\Infrastructure\Repositories\EloquentDeliveryRepository;
use App\Modules\Notifications\Infrastructure\Repositories\EloquentNotificationRepository;
use App\Modules\Notifications\Infrastructure\Repositories\EloquentPreferencesRepository;
use App\Modules\Notifications\Infrastructure\Repositories\EloquentRegistrationRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Notifications' Domain ports to their persistence, crypto and push
 * adapters (P-02). The gateway follows `notifications.push.driver`; at boot
 * the `log` driver is refused outside local and testing and `fcm` without a
 * project id and a readable service-account key (spec 023, FR-041). `disabled`
 * is allowed everywhere. The sealing and lookup keys are required wherever a
 * token is sealed: a missing or short key throws at first use, naming the
 * variable, so a build step that boots the application without secrets
 * (package discovery) still works.
 */
class NotificationsPersistenceServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        RegistrationRepository::class => EloquentRegistrationRepository::class,
        PreferencesRepository::class => EloquentPreferencesRepository::class,
        NotificationRepository::class => EloquentNotificationRepository::class,
        DeliveryRepository::class => EloquentDeliveryRepository::class,
        TokenLookup::class => HmacTokenLookup::class,
    ];

    public function register(): void
    {
        $this->app->singleton(TokenSealer::class, fn (): TokenSealer => new NotificationsSealer(NotificationsKeyring::fromConfig()));
        $this->app->singleton(PushGateway::class, fn (): PushGateway => PushGatewayFactory::make((string) config('notifications.push.driver')));
    }

    public function boot(): void
    {
        PushGatewayFactory::assertBootable((string) config('notifications.push.driver'), $this->app->environment());
    }
}

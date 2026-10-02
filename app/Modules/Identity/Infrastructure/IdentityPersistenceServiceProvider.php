<?php

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Domain\Ports\AddressLookup;
use App\Modules\Identity\Domain\Ports\AttributeCipher;
use App\Modules\Identity\Domain\Ports\AuditStore;
use App\Modules\Identity\Domain\Ports\ChangeSequence;
use App\Modules\Identity\Domain\Ports\FailureCounter;
use App\Modules\Identity\Domain\Ports\LinkRepository;
use App\Modules\Identity\Domain\Ports\ProfileRepository;
use App\Modules\Identity\Domain\Ports\SessionIssuer;
use App\Modules\Identity\Domain\Ports\SessionRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Ports\UserScope;
use App\Modules\Identity\Infrastructure\Console\RewrapKeys;
use App\Modules\Identity\Infrastructure\Crypto\HmacAddressLookup;
use App\Modules\Identity\Infrastructure\Crypto\Keyring;
use App\Modules\Identity\Infrastructure\Crypto\SodiumAttributeCipher;
use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Identity\Infrastructure\Repositories\EloquentAuditStore;
use App\Modules\Identity\Infrastructure\Repositories\EloquentFailureCounter;
use App\Modules\Identity\Infrastructure\Repositories\EloquentLinkRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentProfileRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentSessionRepository;
use App\Modules\Identity\Infrastructure\Repositories\EloquentUserRepository;
use App\Modules\Identity\Infrastructure\Sessions\SanctumSessionIssuer;
use App\Modules\Identity\Infrastructure\Sync\EloquentUserScope;
use App\Modules\Identity\Infrastructure\Sync\UserRowChangeSequence;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Binds Identity's Domain ports to their Infrastructure implementations, and
 * makes the Device Session Sanctum's token record. Lives in Infrastructure,
 * the only layer allowed to name the module's own models (P-02).
 */
class IdentityPersistenceServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        UserRepository::class => EloquentUserRepository::class,
        ProfileRepository::class => EloquentProfileRepository::class,
        SessionRepository::class => EloquentSessionRepository::class,
        SessionIssuer::class => SanctumSessionIssuer::class,
        LinkRepository::class => EloquentLinkRepository::class,
        FailureCounter::class => EloquentFailureCounter::class,
        AuditStore::class => EloquentAuditStore::class,
        ChangeSequence::class => UserRowChangeSequence::class,
        UserScope::class => EloquentUserScope::class,
    ];

    public function register(): void
    {
        $this->app->singleton(Keyring::class, fn (): Keyring => Keyring::fromConfig());
        $this->app->scoped(AttributeCipher::class, fn ($app): SodiumAttributeCipher => new SodiumAttributeCipher($app->make(Keyring::class)));
        $this->app->singleton(AddressLookup::class, fn (): HmacAddressLookup => HmacAddressLookup::fromConfig());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RewrapKeys::class]);
        }

        Sanctum::usePersonalAccessTokenModel(DeviceSession::class);

        // Active and used within the inactivity window, checked against the
        // database on every request: no cache of validity (R-3).
        Sanctum::authenticateAccessTokensUsing(
            fn (DeviceSession $session, bool $isValid): bool => $isValid && $session->isUsable(now()),
        );
    }
}

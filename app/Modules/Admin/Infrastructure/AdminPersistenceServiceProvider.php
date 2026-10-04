<?php

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Ports\EmailLookup;
use App\Modules\Admin\Domain\Ports\OperatorFailureCounter;
use App\Modules\Admin\Domain\Ports\OperatorNotificationStore;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use App\Modules\Admin\Domain\Ports\RecoveryCodeHasher;
use App\Modules\Admin\Domain\Ports\RecoveryCodeStore;
use App\Modules\Admin\Domain\Ports\ReenrolmentStore;
use App\Modules\Admin\Domain\Ports\SecretSealer;
use App\Modules\Admin\Infrastructure\Crypto\AdminKeyring;
use App\Modules\Admin\Infrastructure\Crypto\AdminSealer;
use App\Modules\Admin\Infrastructure\Crypto\HmacEmailLookup;
use App\Modules\Admin\Infrastructure\Crypto\HmacRecoveryCodeHasher;
use App\Modules\Admin\Infrastructure\Repositories\EloquentOperatorFailureCounter;
use App\Modules\Admin\Infrastructure\Repositories\EloquentOperatorNotificationStore;
use App\Modules\Admin\Infrastructure\Repositories\EloquentOperatorRepository;
use App\Modules\Admin\Infrastructure\Repositories\EloquentRecoveryCodeStore;
use App\Modules\Admin\Infrastructure\Repositories\EloquentReenrolmentStore;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Admin's application ports to their persistence (P-02: only
 * Infrastructure names the module's models).
 */
class AdminPersistenceServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        OperatorNotificationStore::class => EloquentOperatorNotificationStore::class,
        OperatorRepository::class => EloquentOperatorRepository::class,
        RecoveryCodeStore::class => EloquentRecoveryCodeStore::class,
        ReenrolmentStore::class => EloquentReenrolmentStore::class,
        OperatorFailureCounter::class => EloquentOperatorFailureCounter::class,
        EmailLookup::class => HmacEmailLookup::class,
        RecoveryCodeHasher::class => HmacRecoveryCodeHasher::class,
    ];

    public function register(): void
    {
        $this->app->singleton(SecretSealer::class, fn (): SecretSealer => new AdminSealer(AdminKeyring::fromConfig()));
    }
}

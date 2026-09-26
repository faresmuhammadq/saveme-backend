<?php

namespace App\Modules\Admin\Infrastructure;

use App\Modules\Admin\Domain\Ports\OperatorNotificationStore;
use App\Modules\Admin\Infrastructure\Repositories\EloquentOperatorNotificationStore;
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
    ];
}

<?php

use App\Modules\Admin\Http\AdminServiceProvider;
use App\Modules\Admin\Infrastructure\AdminPersistenceServiceProvider;
use App\Modules\Billing\Application\BillingServiceProvider;
use App\Modules\Identity\Http\IdentityServiceProvider;
use App\Modules\Identity\Infrastructure\IdentityPersistenceServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    IdentityPersistenceServiceProvider::class,
    IdentityServiceProvider::class,
    BillingServiceProvider::class,
    AdminPersistenceServiceProvider::class,
    AdminServiceProvider::class,
];

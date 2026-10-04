<?php

use App\Modules\Admin\Http\AdminServiceProvider;
use App\Modules\Admin\Infrastructure\AdminPersistenceServiceProvider;
use App\Modules\Billing\Application\BillingServiceProvider;
use App\Modules\Billing\Http\BillingHttpServiceProvider;
use App\Modules\Billing\Infrastructure\BillingPersistenceServiceProvider;
use App\Modules\Budgeting\Application\BudgetingServiceProvider;
use App\Modules\Budgeting\Http\BudgetingHttpServiceProvider;
use App\Modules\Budgeting\Infrastructure\BudgetingPersistenceServiceProvider;
use App\Modules\Identity\Http\IdentityServiceProvider;
use App\Modules\Identity\Infrastructure\IdentityPersistenceServiceProvider;
use App\Modules\Reporting\Application\ReportingServiceProvider;
use App\Modules\Reporting\Http\ReportingHttpServiceProvider;
use App\Modules\Sharing\Application\SharingServiceProvider;
use App\Modules\Sharing\Http\SharingHttpServiceProvider;
use App\Modules\Sharing\Infrastructure\SharingPersistenceServiceProvider;
use App\Modules\Sync\Http\SyncHttpServiceProvider;
use App\Modules\Sync\Infrastructure\SyncPersistenceServiceProvider;
use App\Modules\Wallets\Application\WalletsServiceProvider;
use App\Modules\Wallets\Http\WalletsHttpServiceProvider;
use App\Modules\Wallets\Infrastructure\WalletsPersistenceServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    IdentityPersistenceServiceProvider::class,
    IdentityServiceProvider::class,
    BillingPersistenceServiceProvider::class,
    BillingServiceProvider::class,
    BillingHttpServiceProvider::class,
    WalletsPersistenceServiceProvider::class,
    WalletsServiceProvider::class,
    WalletsHttpServiceProvider::class,
    BudgetingPersistenceServiceProvider::class,
    BudgetingServiceProvider::class,
    BudgetingHttpServiceProvider::class,
    ReportingServiceProvider::class,
    ReportingHttpServiceProvider::class,
    SharingPersistenceServiceProvider::class,
    SharingServiceProvider::class,
    SharingHttpServiceProvider::class,
    SyncPersistenceServiceProvider::class,
    SyncHttpServiceProvider::class,
    AdminPersistenceServiceProvider::class,
    AdminServiceProvider::class,
];

<?php

namespace App\Modules\Sync\Infrastructure;

use App\Modules\Sync\Domain\Ports\CursorSigner;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Sync's Domain ports to their Infrastructure implementations (P-02).
 * Migrations are found by ModuleServiceProvider.
 */
class SyncPersistenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CursorSigner::class, fn (): HmacCursorSigner => HmacCursorSigner::fromConfig());
    }
}

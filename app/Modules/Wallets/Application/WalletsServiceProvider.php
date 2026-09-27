<?php

namespace App\Modules\Wallets\Application;

use App\Modules\Wallets\Application\Provisional\NoTransactionsYet;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Wallets & Ledger's application services. Until B6, the transaction
 * query service answers from the provisional stand-in.
 */
class WalletsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TransactionQueryService::class, NoTransactionsYet::class);
    }
}

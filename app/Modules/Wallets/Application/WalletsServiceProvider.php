<?php

namespace App\Modules\Wallets\Application;

use App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant;
use App\Modules\Wallets\Application\Provisional\NoPostingsYet;
use App\Modules\Wallets\Application\Provisional\NoTransactionsYet;
use App\Modules\Wallets\Domain\Ports\PostingTotals;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Wallets & Ledger's application services. Until B6, the transaction
 * query service and the posting totals answer from provisional stand-ins.
 * Modules with records inside a wallet tag their WalletDeletionParticipant
 * with that interface's name; the wallet service receives them all.
 */
class WalletsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TransactionQueryService::class, NoTransactionsYet::class);
        $this->app->bind(PostingTotals::class, NoPostingsYet::class);

        $this->app->when(WalletService::class)
            ->needs('$participants')
            ->giveTagged(WalletDeletionParticipant::class);
    }
}

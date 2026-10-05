<?php

namespace App\Modules\Wallets\Application;

use App\Modules\Identity\Domain\Events\ProfileUpdated;
use App\Modules\Wallets\Application\Categories\CategoryUsage;
use App\Modules\Wallets\Application\Categories\CategoryUsageQuery;
use App\Modules\Wallets\Application\Categories\ReferencesGoWithWallet;
use App\Modules\Wallets\Application\Categories\WalletCategoryUse;
use App\Modules\Wallets\Application\Categories\WalletCategoryUses;
use App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant;
use App\Modules\Wallets\Application\Departure\LeaveRemainingWallets;
use App\Modules\Wallets\Application\Departure\ReleaseCategories;
use App\Modules\Wallets\Application\Departure\ResolveOwnedWallets;
use App\Modules\Wallets\Application\Export\WalletSections;
use App\Modules\Wallets\Application\Membership\Listeners\ResendMemberNames;
use App\Modules\Wallets\Application\Recurring\RecurringCategoryUsage;
use App\Modules\Wallets\Application\Recurring\RecurringGoesWithWallet;
use App\Modules\Wallets\Application\Recurring\RecurringWalletCategoryUse;
use App\Modules\Wallets\Application\Transactions\LedgerTransactionQueryService;
use App\Modules\Wallets\Application\Transactions\TransactionCategoryUsage;
use App\Modules\Wallets\Application\Transactions\TransactionsGoWithWallet;
use App\Modules\Wallets\Application\Transactions\TransactionWalletCategoryUse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Wallets & Ledger's application services. Modules with records inside a wallet tag their WalletDeletionParticipant
 * with that interface's name; the wallet service receives them all.
 */
class WalletsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance, so a write for a wallet that is already in scope joins the open scope (spec 016, R-10).
        $this->app->singleton(WalletScopeWrites::class);

        $this->app->bind(TransactionQueryService::class, LedgerTransactionQueryService::class);

        $this->app->when(WalletService::class)
            ->needs('$participants')
            ->giveTagged(WalletDeletionParticipant::class);

        $this->app->when(CategoryUsageQuery::class)
            ->needs('$participants')
            ->giveTagged(CategoryUsage::class);

        $this->app->when(WalletCategoryUses::class)
            ->needs('$participants')
            ->giveTagged(WalletCategoryUse::class);

        $this->app->tag([ReferencesGoWithWallet::class, TransactionsGoWithWallet::class, RecurringGoesWithWallet::class], WalletDeletionParticipant::class);
        $this->app->tag([TransactionCategoryUsage::class, RecurringCategoryUsage::class], CategoryUsage::class);
        // Identity orchestrates exports and purges; Wallets supplies its share (spec 022, P-02).
        $this->app->tag([WalletSections::class], 'identity.export-sections');
        $this->app->tag([ResolveOwnedWallets::class, LeaveRemainingWallets::class, ReleaseCategories::class], 'identity.purge-participants');

        $this->app->tag([TransactionWalletCategoryUse::class, RecurringWalletCategoryUse::class], WalletCategoryUse::class);
    }

    public function boot(): void
    {
        Event::listen(ProfileUpdated::class, ResendMemberNames::class);
    }
}

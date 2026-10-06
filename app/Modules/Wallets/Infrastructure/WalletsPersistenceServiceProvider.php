<?php

namespace App\Modules\Wallets\Infrastructure;

use App\Modules\Wallets\Domain\Categories\Ports\CategoryReferenceRepository;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Domain\Categories\Ports\UserScopeSequence as UserScopeSequencePort;
use App\Modules\Wallets\Domain\Ports\ActivityLog;
use App\Modules\Wallets\Domain\Ports\MembershipRepository;
use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Domain\Ports\SyncFeedReader;
use App\Modules\Wallets\Domain\Ports\TombstoneStore;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceReminderSource;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\Ports\RecurringRuleRepository;
use App\Modules\Wallets\Domain\Recurring\Ports\StampBlock;
use App\Modules\Wallets\Domain\Reports\Ports\LedgerReportReader;
use App\Modules\Wallets\Domain\Transactions\Ports\PostingRepository;
use App\Modules\Wallets\Domain\Transactions\Ports\TransactionRepository;
use App\Modules\Wallets\Infrastructure\Ledger\EloquentPostingTotals;
use App\Modules\Wallets\Infrastructure\Reports\SqlLedgerReportReader;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentActivityLog;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentCategoryReferenceRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentCategoryRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentMembershipRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentOccurrenceRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentPostingRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentRecurringRuleRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentSyncFeedReader;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentTombstoneStore;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentTransactionRepository;
use App\Modules\Wallets\Infrastructure\Repositories\EloquentWalletRepository;
use App\Modules\Wallets\Infrastructure\Repositories\SqlOccurrenceReminderSource;
use App\Modules\Wallets\Infrastructure\Sync\UserScopeSequence;
use App\Modules\Wallets\Infrastructure\Sync\WalletScopeSequence;
use Illuminate\Support\ServiceProvider;

/**
 * Binds Wallets' Domain ports to their persistence (P-02). Migrations and
 * factories are found by ModuleServiceProvider.
 */
class WalletsPersistenceServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        WalletRepository::class => EloquentWalletRepository::class,
        MembershipRepository::class => EloquentMembershipRepository::class,
        ScopeSequence::class => WalletScopeSequence::class,
        CategoryRepository::class => EloquentCategoryRepository::class,
        CategoryReferenceRepository::class => EloquentCategoryReferenceRepository::class,
        UserScopeSequencePort::class => UserScopeSequence::class,
        TransactionRepository::class => EloquentTransactionRepository::class,
        PostingRepository::class => EloquentPostingRepository::class,
        PostingTotals::class => EloquentPostingTotals::class,
        SyncFeedReader::class => EloquentSyncFeedReader::class,
        TombstoneStore::class => EloquentTombstoneStore::class,
        RecurringRuleRepository::class => EloquentRecurringRuleRepository::class,
        OccurrenceRepository::class => EloquentOccurrenceRepository::class,
        OccurrenceReminderSource::class => SqlOccurrenceReminderSource::class,
        StampBlock::class => WalletScopeSequence::class,
        ActivityLog::class => EloquentActivityLog::class,
        LedgerReportReader::class => SqlLedgerReportReader::class,
    ];
}

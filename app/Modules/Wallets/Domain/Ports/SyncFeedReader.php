<?php

namespace App\Modules\Wallets\Domain\Ports;

use App\Modules\Wallets\Domain\Categories\CategoryRecord;
use App\Modules\Wallets\Domain\Categories\CategoryReferenceRecord;
use App\Modules\Wallets\Domain\Membership\MemberRecord;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Domain\Transactions\TransactionRecord;

/**
 * What the Wallets tables say to a change feed (spec 013, R-8): for each
 * source, the rows with a position above $after in position order, one page
 * plus one, tombstones included. A wallet source's position is the wallet
 * counter; a person's source's position is the user counter. Rows come out
 * as the same records the read endpoints use.
 */
interface SyncFeedReader
{
    /**
     * The wallet scope's counter and purge horizon; null when the wallet row is gone.
     *
     * @return array{counter: int, purged: int}|null
     */
    public function walletScope(string $walletId): ?array;

    /**
     * @return list<MemberRecord>
     */
    public function membersChangedIn(string $walletId, int $after, int $limit): array;

    /**
     * @return list<CategoryReferenceRecord>
     */
    public function referencesChangedIn(string $walletId, int $after, int $limit): array;

    /**
     * The wallet's own transactions by `change_seq` and the transfers into it
     * by `counter_change_seq`, merged by position.
     *
     * @return list<array{position: int, transaction: TransactionRecord}>
     */
    public function transactionsChangedIn(string $walletId, int $after, int $limit): array;

    /**
     * The wallet's recurring rules by `change_seq`, tombstones included. A
     * transfer rule rides its source wallet's feed only (spec 014, FR-023).
     *
     * @return list<RecurringRuleRecord>
     */
    public function rulesChangedIn(string $walletId, int $after, int $limit): array;

    /**
     * The wallet's occurrences by `change_seq`, tombstones included.
     *
     * @return list<OccurrenceRecord>
     */
    public function occurrencesChangedIn(string $walletId, int $after, int $limit): array;

    /**
     * The person's own categories (never platform rows) by `change_seq`.
     *
     * @return list<CategoryRecord>
     */
    public function categoriesChangedFor(string $userId, int $after, int $limit): array;

    /**
     * The person's own memberships by `member_change_seq`.
     *
     * @return list<MemberRecord>
     */
    public function membershipsChangedFor(string $userId, int $after, int $limit): array;
}

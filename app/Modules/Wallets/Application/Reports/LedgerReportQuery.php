<?php

namespace App\Modules\Wallets\Application\Reports;

use App\Modules\Wallets\Application\Reports\Dto\CategoryRow;
use App\Modules\Wallets\Application\Reports\Dto\DailyRow;
use App\Modules\Wallets\Application\Reports\Dto\LegRow;
use App\Modules\Wallets\Application\Reports\Dto\ReportWallet;
use App\Modules\Wallets\Application\Reports\Dto\ScheduledRow;
use App\Modules\Wallets\Application\WalletQueryService;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use App\Modules\Wallets\Domain\Reports\Ports\LedgerReportReader;
use Closure;

/**
 * What Reporting reads of the ledger (spec 019, research R-2, R-3, R-6): the
 * wallets a person may report on and the grouped sums over their postings,
 * transactions and scheduled occurrences. Every sum is bound to an explicit
 * list of wallet ids the caller obtained from here, and none reads the cached
 * balance (P-05).
 */
final class LedgerReportQuery
{
    public function __construct(
        private readonly LedgerReportReader $reader,
        private readonly WalletQueryService $wallets,
    ) {}

    /**
     * The live wallet a person may read (matrix row W-01).
     *
     * @throws WalletNotFound for a non-member, a former member and a deleted wallet
     */
    public function readableWallet(string $userId, string $walletId): ReportWallet
    {
        $this->wallets->assertCanRead($userId, $walletId);

        $wallet = $this->reader->liveWallet($walletId) ?? throw new WalletNotFound;

        return new ReportWallet($wallet['id'], $wallet['currency'], $wallet['opening_balance_minor']);
    }

    /**
     * The live wallets the person currently belongs to, any role, archived included, ordered by id.
     *
     * @return list<ReportWallet>
     */
    public function walletsOf(string $userId): array
    {
        return array_map(
            fn (array $wallet): ReportWallet => new ReportWallet($wallet['id'], $wallet['currency'], $wallet['opening_balance_minor']),
            $this->reader->walletsOf($userId),
        );
    }

    /**
     * @param  list<string>  $walletIds
     * @return list<LegRow>
     */
    public function walletLegTotals(array $walletIds, string $from, string $to): array
    {
        return array_map(
            fn (array $row): LegRow => new LegRow($row['wallet_id'], $row['kind'], $row['other_wallet_id'], $row['amount_minor']),
            $this->reader->walletLegTotals($walletIds, $from, $to),
        );
    }

    /**
     * Per wallet id, the sum of its legs dated strictly before the date (no opening balance).
     *
     * @param  list<string>  $walletIds
     * @return array<string, int>
     */
    public function balancesBefore(array $walletIds, string $date): array
    {
        $sums = [];

        foreach ($this->reader->balancesBefore($walletIds, $date) as $row) {
            $sums[$row['wallet_id']] = $row['amount_minor'];
        }

        return $sums;
    }

    /**
     * @param  list<string>  $walletIds
     * @return list<CategoryRow>
     */
    public function categoryTotals(array $walletIds, string $from, string $to): array
    {
        return array_map(
            fn (array $row): CategoryRow => new CategoryRow($row['kind'], $row['category_id'], $row['system_key'], $row['amount_minor']),
            $this->reader->categoryTotals($walletIds, $from, $to),
        );
    }

    /**
     * @param  list<string>  $walletIds
     * @return list<DailyRow>
     */
    public function dailyTotals(array $walletIds, string $from, string $to): array
    {
        return array_map(
            fn (array $row): DailyRow => new DailyRow($row['date'], $row['kind'], $row['amount_minor']),
            $this->reader->dailyTotals($walletIds, $from, $to),
        );
    }

    /**
     * @param  list<string>  $walletIds
     * @return list<ScheduledRow>
     */
    public function scheduledTotals(array $walletIds, string $currency, string $from, string $to): array
    {
        return array_map(
            fn (array $row): ScheduledRow => new ScheduledRow($row['date'], $row['kind'], $row['category_id'], $row['system_key'], $row['wallet_id'], $row['counter_wallet_id'], $row['amount_minor']),
            $this->reader->scheduledTotals($walletIds, $currency, $from, $to),
        );
    }

    /**
     * Runs the reads of one report in a single repeatable-read, read-only snapshot.
     *
     * @template T
     *
     * @param  Closure(): T  $reads
     * @return T
     */
    public function snapshot(Closure $reads): mixed
    {
        return $this->reader->snapshot($reads);
    }
}

<?php

namespace App\Modules\Wallets\Domain\Reports\Ports;

use Closure;

/**
 * The ledger sums a report needs (spec 019, research R-2, R-3, R-6). Every
 * method is one grouped query bound to an explicit list of wallet ids the
 * caller may read; none reads the cached balance (P-05) and none sums two
 * currencies, because the caller passes wallets of one currency. Amounts are
 * integer minor units; dates are `YYYY-MM-DD`.
 */
interface LedgerReportReader
{
    /**
     * A live (not deleted) wallet.
     *
     * @return array{id: string, currency: string, opening_balance_minor: int}|null
     */
    public function liveWallet(string $walletId): ?array;

    /**
     * The live wallets the person holds a live membership of, any role,
     * archived included, ordered by id.
     *
     * @return list<array{id: string, currency: string, opening_balance_minor: int}>
     */
    public function walletsOf(string $userId): array;

    /**
     * Wallet-leg sums of live transactions dated in the range, grouped by
     * wallet, kind, the other wallet of a transfer and the sign of the leg.
     *
     * @param  list<string>  $walletIds
     * @return list<array{wallet_id: string, kind: string, other_wallet_id: ?string, amount_minor: int}>
     */
    public function walletLegTotals(array $walletIds, string $from, string $to): array;

    /**
     * Sum of every live wallet leg dated strictly before the date, per wallet.
     *
     * @param  list<string>  $walletIds
     * @return list<array{wallet_id: string, amount_minor: int}>
     */
    public function balancesBefore(array $walletIds, string $date): array;

    /**
     * Category-leg sums of live income and expense transactions of the
     * wallets, dated in the range (expense legs positive, income negative).
     *
     * @param  list<string>  $walletIds
     * @return list<array{kind: string, category_id: string, system_key: ?string, amount_minor: int}>
     */
    public function categoryTotals(array $walletIds, string $from, string $to): array;

    /**
     * Wallet-leg sums of live income and expense transactions, per date and kind.
     *
     * @param  list<string>  $walletIds
     * @return list<array{date: string, kind: string, amount_minor: int}>
     */
    public function dailyTotals(array $walletIds, string $from, string $to): array;

    /**
     * Amounts of live `scheduled` occurrences dated in the range, in the
     * currency, of the wallets (a transfer counts when either side is listed).
     *
     * @param  list<string>  $walletIds
     * @return list<array{date: string, kind: string, category_id: ?string, system_key: ?string, wallet_id: string, counter_wallet_id: ?string, amount_minor: int}>
     */
    public function scheduledTotals(array $walletIds, string $currency, string $from, string $to): array;

    /**
     * Runs the reads in one repeatable-read, read-only snapshot, so every
     * query of a report sees the same committed state (spec 019, FR-017a).
     *
     * @template T
     *
     * @param  Closure(): T  $reads
     * @return T
     */
    public function snapshot(Closure $reads): mixed;
}

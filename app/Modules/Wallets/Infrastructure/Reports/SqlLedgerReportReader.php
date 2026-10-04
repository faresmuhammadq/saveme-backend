<?php

namespace App\Modules\Wallets\Infrastructure\Reports;

use App\Modules\Wallets\Domain\Reports\Ports\LedgerReportReader;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The ledger sums behind reports (spec 019, research R-3 and R-6). Plain SQL,
 * because every statement is a grouped aggregate over postings that Eloquent
 * would only obscure. Each binds its wallet ids as `= ANY(?::uuid[])`, takes
 * `SUM(...)::bigint`, filters `deleted_at IS NULL` and never names the
 * wallet balance cache.
 */
final class SqlLedgerReportReader implements LedgerReportReader
{
    public function liveWallet(string $walletId): ?array
    {
        $row = $this->rows('SELECT id, currency, opening_balance_minor FROM wallets WHERE id = ? AND deleted_at IS NULL', [$walletId])[0] ?? null;

        return $row === null ? null : $this->wallet($row);
    }

    public function walletsOf(string $userId): array
    {
        $rows = $this->rows(
            'SELECT w.id, w.currency, w.opening_balance_minor
            FROM wallets w
            JOIN wallet_members m ON m.wallet_id = w.id AND m.user_id = ? AND m.deleted_at IS NULL
            WHERE w.deleted_at IS NULL
            ORDER BY w.id',
            [$userId],
        );

        return array_map(fn (array $row): array => $this->wallet($row), $rows);
    }

    public function walletLegTotals(array $walletIds, string $from, string $to): array
    {
        $rows = $this->rows(
            "SELECT p.wallet_id, t.kind,
                CASE WHEN t.kind = 'transfer' THEN CASE WHEN p.wallet_id = t.wallet_id THEN t.counter_wallet_id ELSE t.wallet_id END END AS other_wallet_id,
                SUM(p.amount_minor)::bigint AS amount_minor
            FROM postings p
            JOIN transactions t ON t.id = p.transaction_id
            WHERE p.wallet_id = ANY(?::uuid[]) AND t.deleted_at IS NULL AND t.date BETWEEN ?::date AND ?::date
            GROUP BY p.wallet_id, t.kind, other_wallet_id, sign(p.amount_minor)",
            [$this->ids($walletIds), $from, $to],
        );

        return array_map(fn (array $row): array => [
            'wallet_id' => $row['wallet_id'],
            'kind' => $row['kind'],
            'other_wallet_id' => $row['other_wallet_id'],
            'amount_minor' => (int) $row['amount_minor'],
        ], $rows);
    }

    public function balancesBefore(array $walletIds, string $date): array
    {
        $rows = $this->rows(
            'SELECT p.wallet_id, SUM(p.amount_minor)::bigint AS amount_minor
            FROM postings p
            JOIN transactions t ON t.id = p.transaction_id
            WHERE p.wallet_id = ANY(?::uuid[]) AND t.deleted_at IS NULL AND t.date < ?::date
            GROUP BY p.wallet_id',
            [$this->ids($walletIds), $date],
        );

        return array_map(fn (array $row): array => ['wallet_id' => $row['wallet_id'], 'amount_minor' => (int) $row['amount_minor']], $rows);
    }

    public function categoryTotals(array $walletIds, string $from, string $to): array
    {
        $rows = $this->rows(
            'SELECT t.kind, p.category_id, c.system_key, SUM(p.amount_minor)::bigint AS amount_minor
            FROM postings p
            JOIN transactions t ON t.id = p.transaction_id
            JOIN categories c ON c.id = p.category_id
            WHERE p.category_id IS NOT NULL AND t.wallet_id = ANY(?::uuid[]) AND t.deleted_at IS NULL AND t.date BETWEEN ?::date AND ?::date
            GROUP BY t.kind, p.category_id, c.system_key',
            [$this->ids($walletIds), $from, $to],
        );

        return array_map(fn (array $row): array => [
            'kind' => $row['kind'],
            'category_id' => $row['category_id'],
            'system_key' => $row['system_key'],
            'amount_minor' => (int) $row['amount_minor'],
        ], $rows);
    }

    public function dailyTotals(array $walletIds, string $from, string $to): array
    {
        $rows = $this->rows(
            "SELECT t.date::text AS date, t.kind, SUM(p.amount_minor)::bigint AS amount_minor
            FROM postings p
            JOIN transactions t ON t.id = p.transaction_id
            WHERE p.wallet_id = ANY(?::uuid[]) AND t.kind IN ('income', 'expense') AND t.deleted_at IS NULL AND t.date BETWEEN ?::date AND ?::date
            GROUP BY t.date, t.kind",
            [$this->ids($walletIds), $from, $to],
        );

        return array_map(fn (array $row): array => ['date' => $row['date'], 'kind' => $row['kind'], 'amount_minor' => (int) $row['amount_minor']], $rows);
    }

    public function scheduledTotals(array $walletIds, string $currency, string $from, string $to): array
    {
        $ids = $this->ids($walletIds);

        $rows = $this->rows(
            "SELECT o.date::text AS date, o.kind, o.category_id, c.system_key, o.wallet_id, o.counter_wallet_id, SUM(o.amount_minor)::bigint AS amount_minor
            FROM occurrences o
            LEFT JOIN categories c ON c.id = o.category_id
            WHERE o.status = 'scheduled' AND o.deleted_at IS NULL AND o.currency = ? AND o.date BETWEEN ?::date AND ?::date
                AND (o.wallet_id = ANY(?::uuid[]) OR (o.kind = 'transfer' AND o.counter_wallet_id = ANY(?::uuid[])))
            GROUP BY o.date, o.kind, o.category_id, c.system_key, o.wallet_id, o.counter_wallet_id",
            [$currency, $from, $to, $ids, $ids],
        );

        return array_map(fn (array $row): array => [
            'date' => $row['date'],
            'kind' => $row['kind'],
            'category_id' => $row['category_id'],
            'system_key' => $row['system_key'],
            'wallet_id' => $row['wallet_id'],
            'counter_wallet_id' => $row['counter_wallet_id'],
            'amount_minor' => (int) $row['amount_minor'],
        ], $rows);
    }

    public function snapshot(Closure $reads): mixed
    {
        // Only a transaction that has run no statement may change its
        // isolation. A caller already inside one (a test) keeps its own.
        $opensTransaction = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($reads, $opensTransaction): mixed {
            if ($opensTransaction) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $reads();
        });
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $bindings): array
    {
        return array_values(array_map(fn (object $row): array => (array) $row, DB::select($sql, $bindings)));
    }

    /**
     * @param  list<string>  $ids
     */
    private function ids(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, currency: string, opening_balance_minor: int}
     */
    private function wallet(array $row): array
    {
        return ['id' => (string) $row['id'], 'currency' => (string) $row['currency'], 'opening_balance_minor' => (int) $row['opening_balance_minor']];
    }
}

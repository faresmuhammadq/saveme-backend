<?php

namespace App\Modules\Wallets\Infrastructure\Ledger;

use App\Modules\Wallets\Domain\Ports\PostingTotals;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What the ledger says about a wallet's postings (spec 012, R-3). The sum is
 * the wallet's live postings; the currency lock counts tombstoned
 * transactions too, so a restore can never re-post in a currency the wallet
 * no longer has.
 */
final class EloquentPostingTotals implements PostingTotals
{
    public function sumFor(string $walletId): int
    {
        return (int) Posting::query()->where('wallet_id', $walletId)->sum('amount_minor');
    }

    public function hasAny(string $walletId): bool
    {
        return Transaction::query()
            ->where(fn (Builder $query) => $query->where('wallet_id', $walletId)->orWhere('counter_wallet_id', $walletId))
            ->exists();
    }

    public function lockedAmong(array $walletIds): array
    {
        if ($walletIds === []) {
            return [];
        }

        $literal = '{'.implode(',', $walletIds).'}';

        $rows = DB::select(
            'SELECT wallet_id AS id FROM transactions WHERE wallet_id = ANY(?::uuid[])
             UNION
             SELECT counter_wallet_id AS id FROM transactions WHERE counter_wallet_id = ANY(?::uuid[])',
            [$literal, $literal],
        );

        return array_values(array_unique(array_map(fn (mixed $row): string => (string) data_get($row, 'id'), $rows)));
    }
}

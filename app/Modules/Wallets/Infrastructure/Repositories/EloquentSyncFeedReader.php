<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Ports\SyncFeedReader;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Facades\DB;

final class EloquentSyncFeedReader implements SyncFeedReader
{
    public function walletScope(string $walletId): ?array
    {
        $row = Wallet::query()->whereKey($walletId)->toBase()->first(['change_seq', 'purged_change_seq']);

        return $row === null ? null : ['counter' => (int) $row->change_seq, 'purged' => (int) $row->purged_change_seq];
    }

    public function membersChangedIn(string $walletId, int $after, int $limit): array
    {
        return array_values(WalletMember::query()
            ->where('wallet_id', $walletId)
            ->where('change_seq', '>', $after)
            ->orderBy('change_seq')
            ->limit($limit)
            ->get()
            ->map(fn (WalletMember $member) => EloquentMembershipRepository::record($member))
            ->all());
    }

    public function referencesChangedIn(string $walletId, int $after, int $limit): array
    {
        return array_values(CategoryReference::query()
            ->where('wallet_id', $walletId)
            ->where('change_seq', '>', $after)
            ->orderBy('change_seq')
            ->limit($limit)
            ->get()
            ->map(fn (CategoryReference $reference) => EloquentCategoryReferenceRepository::record($reference))
            ->all());
    }

    public function transactionsChangedIn(string $walletId, int $after, int $limit): array
    {
        $rows = array_map(fn (object $row): array => (array) $row, DB::select(
            'SELECT * FROM (
                (SELECT t.*, t.change_seq AS feed_position FROM transactions t WHERE t.wallet_id = ? AND t.change_seq > ? ORDER BY t.change_seq LIMIT ?)
                UNION ALL
                (SELECT t.*, t.counter_change_seq AS feed_position FROM transactions t WHERE t.counter_wallet_id = ? AND t.counter_change_seq > ? ORDER BY t.counter_change_seq LIMIT ?)
            ) feed ORDER BY feed_position LIMIT ?',
            [$walletId, $after, $limit, $walletId, $after, $limit, $limit],
        ));

        return array_values(array_map(fn (array $row): array => [
            'position' => (int) $row['feed_position'],
            'transaction' => EloquentTransactionRepository::rawRecord((object) $row), // @phpstan-ignore argument.type
        ], $rows));
    }

    public function categoriesChangedFor(string $userId, int $after, int $limit): array
    {
        $rows = Category::query()
            ->where('user_id', $userId)
            ->where('change_seq', '>', $after)
            ->orderBy('change_seq')
            ->limit($limit)
            ->toBase()
            ->get();

        return array_values($rows->map(fn (object $row) => EloquentCategoryRepository::rawRecord($row))->all()); // @phpstan-ignore argument.type
    }

    public function membershipsChangedFor(string $userId, int $after, int $limit): array
    {
        return array_values(WalletMember::query()
            ->where('user_id', $userId)
            ->where('member_change_seq', '>', $after)
            ->orderBy('member_change_seq')
            ->limit($limit)
            ->get()
            ->map(fn (WalletMember $member) => EloquentMembershipRepository::record($member))
            ->all());
    }
}

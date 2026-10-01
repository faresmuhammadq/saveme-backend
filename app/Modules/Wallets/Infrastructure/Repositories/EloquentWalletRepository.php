<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Ports\WalletRepository;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;
use App\Modules\Wallets\Domain\Wallets\WalletType;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class EloquentWalletRepository implements WalletRepository
{
    public function find(string $walletId): ?WalletRecord
    {
        $wallet = Wallet::query()->find($walletId);

        return $wallet === null ? null : self::record($wallet);
    }

    public function findForUpdate(string $walletId): ?WalletRecord
    {
        $wallet = Wallet::query()->whereKey($walletId)->lockForUpdate()->first();

        return $wallet === null ? null : self::record($wallet);
    }

    public function insert(string $walletId, array $values, int $postingsTotal): void
    {
        Wallet::query()->forceCreate([
            'id' => $walletId,
            ...$values,
            'balance_minor' => $values['opening_balance_minor'] + $postingsTotal,
            'version' => 1,
            'change_seq' => 1,
        ]);
    }

    public function updateIfVersion(string $walletId, int $expectedVersion, array $columns): bool
    {
        return Wallet::query()
            ->whereKey($walletId)
            ->where('version', $expectedVersion)
            ->toBase()
            ->update([
                ...$columns,
                'version' => $expectedVersion + 1,
                'updated_at' => now()->format('Y-m-d H:i:s.uP'),
            ]) === 1;
    }

    public function refreshBalance(string $walletId, int $postingsTotal): bool
    {
        $row = DB::selectOne(
            'WITH prior AS (SELECT id, balance_minor FROM wallets WHERE id = ? FOR UPDATE)
             UPDATE wallets w SET balance_minor = w.opening_balance_minor + ?
             FROM prior WHERE w.id = prior.id
             RETURNING prior.balance_minor <> w.balance_minor AS drifted',
            [$walletId, $postingsTotal],
        );

        return $row !== null && (bool) $row->drifted;
    }

    public function listForMember(string $userId, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $column = $sort === 'name' ? 'wallets.name COLLATE "C"' : 'wallets.created_at';
        $cast = $sort === 'name' ? '?::text' : '?::timestamptz';
        $direction = $descending ? 'DESC' : 'ASC';

        $query = Wallet::query()
            ->join('wallet_members as m', 'm.wallet_id', '=', 'wallets.id')
            ->where('m.user_id', $userId)
            ->whereNull('m.deleted_at')
            ->whereNull('wallets.deleted_at')
            ->select('wallets.*', 'm.role as member_role');

        if ($after !== null) {
            $query->whereRaw("({$column}, wallets.id) ".($descending ? '<' : '>')." ({$cast}, ?::uuid)", [$after['value'], $after['id']]);
        }

        return array_values($query
            ->orderByRaw("{$column} {$direction}, wallets.id {$direction}")
            ->limit($limit + 1)
            ->get()
            ->map(fn (Wallet $wallet): array => ['wallet' => self::record($wallet), 'role' => Role::from((string) $wallet->getAttribute('member_role'))])
            ->all());
    }

    public function sortValue(WalletRecord $wallet, string $sort): string
    {
        return $sort === 'name'
            ? $wallet->name
            : $wallet->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public function countOwnedLive(string $userId): int
    {
        return Wallet::query()
            ->join('wallet_members as m', 'm.wallet_id', '=', 'wallets.id')
            ->where('m.user_id', $userId)
            ->where('m.role', Role::Owner->value)
            ->whereNull('m.deleted_at')
            ->whereNull('wallets.deleted_at')
            ->count();
    }

    public function liveIds(): array
    {
        return array_values(Wallet::query()->whereNull('deleted_at')->orderBy('created_at')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all());
    }

    private static function record(Wallet $wallet): WalletRecord
    {
        return new WalletRecord(
            id: $wallet->id,
            type: WalletType::from($wallet->type),
            name: $wallet->name,
            currency: $wallet->currency,
            openingBalanceMinor: $wallet->opening_balance_minor,
            balanceMinor: $wallet->balance_minor,
            icon: $wallet->icon,
            color: $wallet->color,
            archivedAt: $wallet->archived_at?->toDateTimeImmutable(),
            version: $wallet->version,
            changeSeq: $wallet->change_seq,
            createdAt: $wallet->created_at->toDateTimeImmutable(),
            updatedAt: $wallet->updated_at->toDateTimeImmutable(),
            deletedAt: $wallet->deleted_at?->toDateTimeImmutable(),
        );
    }
}

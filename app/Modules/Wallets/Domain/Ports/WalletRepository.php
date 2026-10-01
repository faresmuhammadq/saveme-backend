<?php

namespace App\Modules\Wallets\Domain\Ports;

use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;

interface WalletRepository
{
    /**
     * Any wallet, tombstones included.
     */
    public function find(string $walletId): ?WalletRecord;

    /**
     * As find(), taking the row lock that serialises every write in the scope.
     */
    public function findForUpdate(string $walletId): ?WalletRecord;

    /**
     * Inserts version 1, change_seq 1, the cache set to opening + $postingsTotal.
     *
     * @param  array{type: string, name: string, currency: string, opening_balance_minor: int, icon: ?string, color: ?string}  $values
     */
    public function insert(string $walletId, array $values, int $postingsTotal): void;

    /**
     * Applies $columns, moves version to $expectedVersion + 1 and stamps
     * updated_at, only while the stored version is still $expectedVersion.
     *
     * @param  array<string, scalar|null>  $columns
     */
    public function updateIfVersion(string $walletId, int $expectedVersion, array $columns): bool;

    /**
     * Sets the cached balance to the stored opening balance + $postingsTotal.
     * Never uses the cache as an input (P-05 rule 5).
     *
     * @return bool whether the cache differed from the recomputed value, which
     *              is how the drift check learns of drift without reading the cache
     */
    public function refreshBalance(string $walletId, int $postingsTotal): bool;

    /**
     * The caller's live wallets (archived included), one page plus one.
     *
     * @param  'name'|'created_at'  $sort
     * @param  array{value: string, id: string}|null  $after
     * @return list<array{wallet: WalletRecord, role: Role}>
     */
    public function listForMember(string $userId, string $sort, bool $descending, ?array $after, int $limit): array;

    /**
     * The sort value a cursor carries for a wallet.
     *
     * @param  'name'|'created_at'  $sort
     */
    public function sortValue(WalletRecord $wallet, string $sort): string;

    /**
     * Non-tombstoned wallets the person currently owns (archived included).
     */
    public function countOwnedLive(string $userId): int;

    /**
     * Ids of every live wallet, for the drift check.
     *
     * @return list<string>
     */
    public function liveIds(): array;
}

<?php

namespace App\Modules\Wallets\Domain\Ports;

use DateTimeImmutable;

/**
 * What the tombstone purge reads and removes in the Wallets tables (spec 013,
 * R-11). Every method runs inside the caller's transaction, which holds the
 * scope locks: category rows first, then wallet rows ascending, then user
 * rows ascending (ADR-0017 rule 5). `$before` is the retention cut: only a
 * tombstone whose `deleted_at` is earlier is touched.
 *
 * `next…` methods page with a keyset on id (`$afterId`), so a row a batch
 * could not remove never makes the run loop.
 */
interface TombstoneStore
{
    /**
     * @return list<array{id: string, wallet_id: string, counter_wallet_id: ?string}>
     */
    public function nextExpiredTransactions(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * @return list<array{id: string, wallet_id: string}>
     */
    public function nextExpiredOccurrences(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * @return list<array{id: string, wallet_id: string}>
     */
    public function nextExpiredRules(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * @return list<array{id: string, wallet_id: string}>
     */
    public function nextExpiredReferences(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * @return list<array{id: string, wallet_id: string}>
     */
    public function nextExpiredMembers(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * @return list<array{id: string, user_id: string}>
     */
    public function nextExpiredCategories(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * @return list<string> ids of tombstoned wallets past the cut
     */
    public function nextExpiredWallets(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * Locks the wallet rows in ascending id.
     *
     * @param  list<string>  $walletIds
     */
    public function lockWallets(array $walletIds): void;

    /**
     * Locks the category rows in ascending id.
     *
     * @param  list<string>  $categoryIds
     */
    public function lockCategories(array $categoryIds): void;

    /**
     * Removes the transactions still tombstoned past the cut and holding no posting.
     *
     * @param  list<string>  $ids
     * @return list<array{wallet_id: string, counter_wallet_id: ?string, change_seq: int, counter_change_seq: ?int}>
     */
    public function deleteTransactions(array $ids, DateTimeImmutable $before): array;

    /**
     * Removes the occurrences still tombstoned past the cut that no
     * transaction refers to (spec 014, FR-025).
     *
     * @param  list<string>  $ids
     * @return list<array{wallet_id: string, change_seq: int}>
     */
    public function deleteOccurrences(array $ids, DateTimeImmutable $before): array;

    /**
     * Removes the recurring rules still tombstoned past the cut that no
     * occurrence, live or tombstoned, and no transaction refers to.
     *
     * @param  list<string>  $ids
     * @return list<array{wallet_id: string, change_seq: int}>
     */
    public function deleteRules(array $ids, DateTimeImmutable $before): array;

    /**
     * @param  list<string>  $ids
     * @return list<array{wallet_id: string, change_seq: int}>
     */
    public function deleteReferences(array $ids, DateTimeImmutable $before): array;

    /**
     * @param  list<string>  $ids
     * @return list<array{wallet_id: string, user_id: string, change_seq: int, member_change_seq: int}>
     */
    public function deleteMembers(array $ids, DateTimeImmutable $before): array;

    /**
     * Removes the categories still tombstoned past the cut that no transaction,
     * posting, category reference, recurring rule or occurrence refers to.
     *
     * @param  list<string>  $ids
     * @return list<array{user_id: string, change_seq: int}>
     */
    public function deleteUnreferencedCategories(array $ids, DateTimeImmutable $before): array;

    /**
     * Removes the wallets still tombstoned past the cut that no transaction,
     * posting, category reference, membership, recurring rule or occurrence
     * refers to.
     *
     * @param  list<string>  $ids
     */
    public function deleteUnreferencedWallets(array $ids, DateTimeImmutable $before): int;

    /**
     * Raises the wallet scope's purge horizon; never lowers it.
     */
    public function raiseWalletHorizon(string $walletId, int $position): void;
}

<?php

namespace App\Modules\Wallets\Domain\Transactions\Ports;

use App\Modules\Wallets\Domain\Transactions\TransactionRecord;

interface TransactionRepository
{
    /**
     * Any transaction, tombstones included.
     */
    public function find(string $transactionId): ?TransactionRecord;

    /**
     * Whether a transaction, live or tombstoned, has the identifier: a wallet
     * or a category may not be created under it (P-06 rule 3, spec 012 R-10).
     */
    public function exists(string $transactionId): bool;

    /**
     * As find(), taking the row lock.
     */
    public function findForUpdate(string $transactionId): ?TransactionRecord;

    /**
     * Whether the id is held by anything the caller may not overwrite from
     * this wallet's path: a wallet, a category (a person's or the
     * platform's), a category reference, a wallet membership, or a
     * transaction touching neither `wallet_id` nor `counter_wallet_id` as
     * the path wallet (P-06 rule 3, spec 012 R-10).
     */
    public function isHeldByOther(string $transactionId, string $pathWalletId): bool;

    /**
     * Inserts version 1.
     *
     * @param  array{kind: string, date: string, amount_minor: int, currency: string, category_id: ?string, counter_wallet_id: ?string, description: ?string, memo: ?string, counterparty: ?string, cleared: bool}  $values
     */
    public function insert(string $transactionId, string $walletId, array $values, string $createdBy, int $changeSeq, ?int $counterChangeSeq): void;

    /**
     * Applies $columns, moves version to $expectedVersion + 1, stamps
     * updated_at and the given sequences, only while the stored version is
     * still $expectedVersion. A null sequence leaves its column as it is.
     *
     * @param  array<string, scalar|null>  $columns
     */
    public function updateIfVersion(string $transactionId, int $expectedVersion, array $columns, ?int $changeSeq, ?int $counterChangeSeq): bool;

    /**
     * Whether any transaction, live or tombstoned, in the wallet references
     * the category, other than the one named.
     */
    public function anyInWalletUsing(string $walletId, string $categoryId, string $exceptTransactionId): bool;

    /**
     * Whether any transaction, live or tombstoned, references the category.
     */
    public function categoryUsed(string $categoryId): bool;

    /**
     * Which of the categories any transaction, live or tombstoned,
     * references: one query for the whole set.
     *
     * @param  list<string>  $categoryIds
     * @return list<string>
     */
    public function categoriesUsedAmong(array $categoryIds): array;

    /**
     * Live transactions of the wallet's list: its own and the transfers into
     * it. One page plus one, keyset on (sort value, id).
     *
     * @param  array<string, list<array{op: string, value: string}>>  $filters  date, category_id, kind
     * @param  'date'|'created_at'  $sort
     * @param  array{value: string, id: string}|null  $after
     * @return list<TransactionRecord>
     */
    public function listLive(string $walletId, array $filters, ?string $search, string $sort, bool $descending, ?array $after, int $limit): array;

    /**
     * The sort value a cursor carries for a transaction.
     *
     * @param  'date'|'created_at'  $sort
     */
    public function sortValue(TransactionRecord $transaction, string $sort): string;

    /**
     * Whether the person recorded at least one live transaction, in any
     * wallet (locks their default currency, spec 008 R-3).
     */
    public function anyLiveRecordedBy(string $userId): bool;

    /**
     * Live transactions that go with the wallet when it is deleted: those
     * whose other wallet is absent or already deleted.
     *
     * @return list<TransactionRecord>
     */
    public function liveGoingWith(string $walletId): array;
}

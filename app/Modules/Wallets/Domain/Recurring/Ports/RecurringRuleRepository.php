<?php

namespace App\Modules\Wallets\Domain\Recurring\Ports;

use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;

interface RecurringRuleRepository
{
    /**
     * Any rule, tombstones included.
     */
    public function find(string $ruleId): ?RecurringRuleRecord;

    /**
     * As find(), taking the row lock that serialises every write to the rule.
     */
    public function findForUpdate(string $ruleId): ?RecurringRuleRecord;

    /**
     * Whether the id is held by anything the caller may not overwrite from
     * this wallet's path: a wallet, a category, a category reference, a
     * membership, a transaction, an occurrence, or a rule of another wallet
     * (P-06 rule 3).
     */
    public function isHeldByOther(string $ruleId, string $pathWalletId): bool;

    /**
     * Inserts version 1.
     *
     * @param  array<string, scalar|null>  $values  the writable members, as columns
     */
    public function insert(string $ruleId, string $walletId, array $values, string $timezone, string $createdBy, string $autoConfirmFrom, int $changeSeq): void;

    /**
     * Applies $columns, moves version to $expectedVersion + 1, stamps
     * updated_at and change_seq, only while the stored version is still
     * $expectedVersion.
     *
     * @param  array<string, scalar|null>  $columns
     */
    public function updateIfVersion(string $ruleId, int $expectedVersion, array $columns, int $changeSeq): bool;

    /**
     * The wallet's live rules, every status, one page plus one, keyset on
     * (sort value, id).
     *
     * @param  array<string, list<array{op: string, value: string}>>  $filters  status
     * @param  'created_at'|'start_date'  $sort
     * @param  array{value: string, id: string}|null  $after
     * @return list<RecurringRuleRecord>
     */
    public function listLive(string $walletId, array $filters, string $sort, bool $descending, ?array $after, int $limit): array;

    /**
     * The sort value a cursor carries for a rule.
     *
     * @param  'created_at'|'start_date'  $sort
     */
    public function sortValue(RecurringRuleRecord $rule, string $sort): string;

    /**
     * The next page of live, active rules by id, for the job (a paused or
     * ended rule has nothing to materialise or confirm).
     *
     * @return list<RecurringRuleRecord>
     */
    public function nextForJob(string $afterId, int $limit): array;

    /**
     * Live rules of the wallet (it is their wallet, not a transfer's
     * destination), which go with it when it is deleted.
     *
     * @return list<RecurringRuleRecord>
     */
    public function liveInWallet(string $walletId): array;

    /**
     * Whether any rule, live or tombstoned, uses the category as its
     * category or payout category.
     */
    public function categoryUsed(string $categoryId): bool;

    /**
     * Which of the categories any rule, live or tombstoned, uses: one query
     * for the whole set.
     *
     * @param  list<string>  $categoryIds
     * @return list<string>
     */
    public function categoriesUsedAmong(array $categoryIds): array;

    /**
     * Whether any rule of the wallet, live or tombstoned, uses the category.
     */
    public function anyInWalletUsing(string $walletId, string $categoryId): bool;
}

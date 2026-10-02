<?php

namespace App\Modules\Wallets\Domain\Recurring\Ports;

use App\Modules\Wallets\Domain\Recurring\OccurrencePlan;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;

interface OccurrenceRepository
{
    /**
     * Any occurrence, tombstones included.
     */
    public function find(string $occurrenceId): ?OccurrenceRecord;

    /**
     * As find(), taking the row lock.
     */
    public function findForUpdate(string $occurrenceId): ?OccurrenceRecord;

    /**
     * Locks the rule's live occurrences in ascending id and returns them.
     *
     * @return list<OccurrenceRecord>
     */
    public function lockLiveForRule(string $ruleId): array;

    /**
     * Every occurrence key the rule holds with a scheduled date in
     * [$from, $to], live or tombstoned, with the row it names.
     *
     * @return array<string, array{id: string, deleted: bool, status: string}> keyed `scheduled_date|0` or `scheduled_date|1` (payout)
     */
    public function existingKeys(string $ruleId, string $from, string $to): array;

    /**
     * One multi-row INSERT … ON CONFLICT (recurring_rule_id, scheduled_date,
     * is_payout) DO NOTHING, with server-minted ids, version 1 and the
     * consecutive stamps $firstChangeSeq … for the plans in order.
     *
     * @param  list<OccurrencePlan>  $plans
     * @return int the rows actually inserted
     */
    public function insertMany(string $ruleId, string $walletId, array $plans, int $firstChangeSeq): int;

    /**
     * Applies $columns, moves version to $expectedVersion + 1, stamps
     * updated_at and change_seq, only while the stored version is still
     * $expectedVersion.
     *
     * @param  array<string, scalar|null>  $columns
     */
    public function updateIfVersion(string $occurrenceId, int $expectedVersion, array $columns, int $changeSeq): bool;

    /**
     * Sets the same $columns on every row, each version + 1 and each with
     * its own consecutive change_seq from $firstChangeSeq in id order, in one
     * statement. The rows are locked by the caller.
     *
     * @param  list<string>  $ids
     * @param  array<string, scalar|null>  $columns
     */
    public function updateMany(array $ids, array $columns, int $firstChangeSeq): void;

    /**
     * Whether the rule has a live confirmed or skipped occurrence.
     */
    public function hasResolved(string $ruleId): bool;

    /**
     * The latest resolved round of the rule: its highest ordinal and its
     * latest scheduled date; null when none is resolved.
     *
     * @return array{ordinal: int, scheduled_date: string}|null
     */
    public function latestResolved(string $ruleId): ?array;

    /**
     * The rule's live scheduled occurrences that no refusal excludes, whose
     * effective date lies in [$from, $today], oldest date first, locked in
     * that order for the automatic confirmation.
     *
     * @return list<OccurrenceRecord>
     */
    public function dueForAutoConfirm(string $ruleId, string $from, string $today, int $limit): array;

    /**
     * Whether dueForAutoConfirm() would find anything, unlocked and cheap:
     * the job's check before it takes a single lock.
     */
    public function hasDueForAutoConfirm(string $ruleId, string $from, string $today): bool;

    /**
     * Categories the same scheduled occurrences use, for the unlocked probe
     * that decides which categories to lock first.
     *
     * @return list<string>
     */
    public function dueCategoryIds(string $ruleId, string $from, string $today, int $limit): array;

    /**
     * Whether the id is held by any record at all: a wallet, a category, a
     * category reference, a membership, a transaction, a rule or an
     * occurrence.
     */
    public function isIdTaken(string $id): bool;

    /**
     * The wallet's live occurrences, one page plus one, keyset on (sort
     * value, id).
     *
     * @param  array<string, list<array{op: string, value: string}>>  $filters  recurring_rule_id, status, date
     * @param  'date'|'created_at'  $sort
     * @param  array{value: string, id: string}|null  $after
     * @return list<OccurrenceRecord>
     */
    public function listLive(string $walletId, array $filters, string $sort, bool $descending, ?array $after, int $limit): array;

    /**
     * The sort value a cursor carries for an occurrence.
     *
     * @param  'date'|'created_at'  $sort
     */
    public function sortValue(OccurrenceRecord $occurrence, string $sort): string;

    /**
     * Live occurrences of the wallet, for its deletion; locked in id order.
     *
     * @return list<OccurrenceRecord>
     */
    public function liveInWallet(string $walletId): array;

    /**
     * Whether any occurrence, live or tombstoned, uses the category.
     */
    public function categoryUsed(string $categoryId): bool;

    /**
     * Which of the categories any occurrence, live or tombstoned, uses.
     *
     * @param  list<string>  $categoryIds
     * @return list<string>
     */
    public function categoriesUsedAmong(array $categoryIds): array;

    /**
     * Whether any occurrence of the wallet, live or tombstoned, uses the category.
     */
    public function anyInWalletUsing(string $walletId, string $categoryId): bool;

    /**
     * Revives a tombstoned, still scheduled occurrence the schedule gives
     * again: live, the plan's values, version + 1, the stamp.
     */
    public function revive(string $occurrenceId, OccurrencePlan $plan, int $changeSeq): void;
}

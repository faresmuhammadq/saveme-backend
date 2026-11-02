<?php

namespace App\Modules\Budgeting\Domain\SharedBudgets\Ports;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetEntryRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetLineRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRole;
use DateTimeImmutable;

/**
 * Persistence of the Shared Budget aggregate: the budget and its members,
 * lines, dated history and entries.
 */
interface SharedBudgetRepository
{
    public function find(string $id): ?SharedBudgetRecord;

    public function findForUpdate(string $id): ?SharedBudgetRecord;

    /**
     * Whether the identifier already names a budget, a line or a person's Budget (P-06 rule 3).
     */
    public function identifierTaken(string $id): bool;

    /**
     * @param  array{name: string, currency: string, start_day: int, limit_minor: ?int}  $values
     */
    public function insert(string $id, string $ownerId, string $timezone, array $values): void;

    /**
     * Writes the columns and moves the version; the row is already locked.
     *
     * @param  array<string, mixed>  $columns
     */
    public function update(string $id, array $columns): void;

    /**
     * Takes the scope's next stamp; the row is already locked.
     */
    public function nextSeq(string $id): int;

    /**
     * The live budgets the person is an active member of, by `created_at`.
     *
     * @param  array{value: string, id: string}|null  $after
     * @return list<SharedBudgetRecord>
     */
    public function listForMember(string $userId, bool $descending, ?array $after, int $limit): array;

    public function sortValue(SharedBudgetRecord $budget): string;

    public function activeMember(string $budgetId, string $userId): ?SharedBudgetMemberRecord;

    /**
     * Active members, the owner first and then by joining order.
     *
     * @return list<SharedBudgetMemberRecord>
     */
    public function activeMembers(string $budgetId): array;

    public function insertMember(string $budgetId, string $userId, SharedBudgetRole $role, int $changeSeq, int $memberChangeSeq): SharedBudgetMemberRecord;

    /**
     * @param  array<string, mixed>  $columns
     */
    public function updateMember(string $memberId, array $columns, int $changeSeq, int $memberChangeSeq): void;

    /**
     * @return list<string>
     */
    public function liveBudgetIdsOf(string $userId): array;

    /**
     * Live budgets the person owns, ascending by id.
     *
     * @return list<string>
     */
    public function ownedLiveBudgetIds(string $userId): array;

    public function findLine(string $lineId): ?SharedBudgetLineRecord;

    /**
     * @param  array{category_id: string, limit_minor: ?int, name: ?string, icon: ?string, color: ?string}  $values
     */
    public function insertLine(string $lineId, string $budgetId, array $values, int $changeSeq): void;

    /**
     * @param  array<string, mixed>  $columns
     */
    public function updateLine(string $lineId, array $columns, int $changeSeq): void;

    /**
     * @return list<SharedBudgetLineRecord>
     */
    public function liveLines(string $budgetId): array;

    /**
     * Every line of the budget, deleted ones included, by creation.
     *
     * @return list<SharedBudgetLineRecord>
     */
    public function allLines(string $budgetId): array;

    /**
     * Whether any line of the budget, live or tombstoned, names the category.
     */
    public function lineNames(string $budgetId, string $categoryId): bool;

    public function categoryUsed(string $categoryId): bool;

    /**
     * @param  list<string>  $categoryIds
     * @return list<string>
     */
    public function categoriesUsedAmong(array $categoryIds): array;

    /**
     * Budgets with a live line naming the category, in ascending id.
     *
     * @return list<string>
     */
    public function budgetIdsWithLiveLineFor(string $categoryId): array;

    /**
     * Sets the limit from a date on; a second change in one period replaces the first.
     */
    public function recordLimit(string $budgetId, ?string $lineId, ?int $limitMinor, string $appliesFrom, int $changeSeq): void;

    /**
     * @return list<array{line_id: ?string, limit_minor: ?int, applies_from: string}>
     */
    public function limitHistory(string $budgetId): array;

    public function recordStartDay(string $budgetId, int $startDay, ?string $appliesFrom, int $changeSeq): void;

    /**
     * @return list<array{start_day: int, applies_from: ?string}>
     */
    public function startDayHistory(string $budgetId): array;

    public function entryFor(string $budgetId, string $transactionId): ?SharedBudgetEntryRecord;

    /**
     * @param  array{created_by: string, category_id: string, date: string, amount_minor: int, description: ?string}  $values
     */
    public function insertEntry(string $budgetId, string $transactionId, array $values, int $changeSeq): void;

    /**
     * @param  array<string, mixed>  $columns
     */
    public function updateEntry(string $entryId, array $columns, int $changeSeq): void;

    /**
     * Live entries of one author, detached or not.
     *
     * @return list<SharedBudgetEntryRecord>
     */
    public function followingEntriesOf(string $budgetId, string $authorId): array;

    /**
     * Every entry a person wrote, tombstones included, by id after `$afterId`.
     *
     * @return list<SharedBudgetEntryRecord>
     */
    public function entriesAuthoredBy(string $userId, string $afterId, int $limit): array;

    /**
     * Live entries, whatever their state.
     *
     * @return list<SharedBudgetEntryRecord>
     */
    public function liveEntries(string $budgetId): array;

    /**
     * Live, following entries of a set of transactions, for a deleted wallet.
     *
     * @param  list<string>  $transactionIds
     * @return list<SharedBudgetEntryRecord>
     */
    public function followingEntriesForTransactions(array $transactionIds): array;

    /**
     * Spending per author and category within calendar dates, live entries only.
     *
     * @return list<array{created_by: string, category_id: string, amount_minor: int}>
     */
    public function totals(string $budgetId, string $from, string $to): array;

    /**
     * Records of the budget's scope above a position, tombstones included, by change_seq.
     *
     * @return list<SharedBudgetMemberRecord>
     */
    public function membersChangedAfter(string $budgetId, int $after, int $limit): array;

    /**
     * @return list<SharedBudgetLineRecord>
     */
    public function linesChangedAfter(string $budgetId, int $after, int $limit): array;

    /**
     * @return list<SharedBudgetEntryRecord>
     */
    public function entriesChangedAfter(string $budgetId, int $after, int $limit): array;

    /**
     * A person's own memberships above a position of their user scope, ended
     * ones included, by `member_change_seq` (ADR-0017 rule 3).
     *
     * @return list<SharedBudgetMemberRecord>
     */
    public function membershipsChangedAfter(string $userId, int $after, int $limit): array;

    /**
     * The scope head and purge horizon of each live budget the person actively
     * belongs to, ascending by id after `$afterId`.
     *
     * @return list<array{shared_budget_id: string, change_seq: int, purged_change_seq: int}>
     */
    public function headsFor(string $userId, ?string $afterId, int $limit): array;

    /**
     * Tombstoned children deleted before the cut, ascending by id after `$afterId`.
     *
     * @param  'members'|'lines'|'entries'  $kind
     * @return list<array{id: string, shared_budget_id: string, user_id: ?string, change_seq: int, member_change_seq: ?int}>
     */
    public function expiredTombstones(string $kind, DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * @param  'members'|'lines'|'entries'  $kind
     * @param  list<string>  $ids
     */
    public function deleteTombstones(string $kind, array $ids, DateTimeImmutable $before): int;

    public function raiseHorizon(string $budgetId, int $position): void;

    /**
     * Tombstoned budgets deleted before the cut, ascending by id after `$afterId`.
     *
     * @return list<string>
     */
    public function expiredBudgets(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * Removes a tombstoned budget and everything under it. No reader is left.
     */
    public function deleteBudget(string $budgetId): void;

    public function now(): DateTimeImmutable;
}

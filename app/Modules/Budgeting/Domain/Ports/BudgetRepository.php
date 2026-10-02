<?php

namespace App\Modules\Budgeting\Domain\Ports;

use App\Modules\Budgeting\Domain\BudgetRecord;
use DateTimeImmutable;

interface BudgetRepository
{
    /**
     * The person's budget, tombstone included; null when it is another
     * person's, another kind of record's, or unknown.
     */
    public function findOwned(string $budgetId, string $ownerId): ?BudgetRecord;

    /**
     * The same under the row lock that serialises every write to it.
     */
    public function findOwnedForUpdate(string $budgetId, string $ownerId): ?BudgetRecord;

    /**
     * Whether the id is held by another person's budget.
     */
    public function isHeldByOther(string $budgetId, string $ownerId): bool;

    /**
     * Inserts version 1.
     *
     * @param  array{category_id: ?string, period_type: string, amount_minor: int, currency: string}  $values
     */
    public function insert(string $budgetId, string $ownerId, array $values, int $changeSeq): void;

    /**
     * Applies $columns, moves version to $expectedVersion + 1, stamps
     * updated_at and change_seq, only while the stored version is still
     * $expectedVersion.
     *
     * @param  array<string, scalar|null>  $columns
     */
    public function updateIfVersion(string $budgetId, int $expectedVersion, array $columns, int $changeSeq): bool;

    /**
     * The person's live budgets (archived included unless `$activeOnly`) in
     * (created_at, id) order, one page plus one.
     *
     * @param  array{value: string, id: string}|null  $after
     * @return list<BudgetRecord>
     */
    public function listLive(string $userId, bool $activeOnly, bool $descending, ?array $after, int $limit): array;

    /**
     * The sort value a cursor carries for a budget.
     */
    public function sortValue(BudgetRecord $budget): string;

    /**
     * The person's budgets above a user-scope position, tombstones included.
     *
     * @return list<BudgetRecord>
     */
    public function changedFor(string $userId, int $after, int $limit): array;

    /**
     * Whether any budget, live or tombstoned, names the category.
     */
    public function categoryUsed(string $categoryId): bool;

    /**
     * Which of the categories any budget names.
     *
     * @param  list<string>  $categoryIds
     * @return list<string>
     */
    public function categoriesUsedAmong(array $categoryIds): array;

    /**
     * The next batch of tombstones deleted before the cut, after an id.
     *
     * @return list<array{id: string, user_id: string, change_seq: int}>
     */
    public function nextExpiredTombstones(DateTimeImmutable $before, string $afterId, int $limit): array;

    /**
     * Deletes the tombstones deleted before the cut, returning what went.
     *
     * @param  list<string>  $ids
     * @return list<array{user_id: string, change_seq: int}>
     */
    public function deleteTombstones(array $ids, DateTimeImmutable $before): array;
}

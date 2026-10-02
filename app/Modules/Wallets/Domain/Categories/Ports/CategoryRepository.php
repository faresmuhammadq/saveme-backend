<?php

namespace App\Modules\Wallets\Domain\Categories\Ports;

use App\Modules\Wallets\Domain\Categories\CategoryRecord;
use App\Modules\Wallets\Domain\Categories\PlatformCategoryRecord;

interface CategoryRepository
{
    /**
     * Any person's category, tombstones included; null for a platform row or
     * no row.
     */
    public function findAny(string $categoryId): ?CategoryRecord;

    /**
     * The caller's own category under the row lock that serialises every
     * write to it, tombstones included. Null when the id is another person's,
     * a platform category's, or unknown.
     */
    public function findOwnedForUpdate(string $categoryId, string $ownerId): ?CategoryRecord;

    /**
     * Any person's category under a share lock, taken by publishers (B6, B7)
     * before they lock a wallet (spec FR-023).
     */
    public function findForShare(string $categoryId): ?CategoryRecord;

    /**
     * A platform category under a share lock.
     */
    public function findPlatformForShare(string $categoryId): ?PlatformCategoryRecord;

    /**
     * Whether the id is held by anything the caller may not overwrite: a
     * platform category, another person's category, a wallet, or a category
     * reference (P-06 rule 3).
     */
    public function isHeldByOther(string $categoryId, string $ownerId): bool;

    /**
     * Inserts version 1.
     *
     * @param  array{kind: string, name: string, icon: ?string, color: ?string}  $values
     */
    public function insert(string $categoryId, string $ownerId, array $values, int $changeSeq): void;

    /**
     * Applies $columns, moves version to $expectedVersion + 1, stamps
     * updated_at and change_seq, only while the stored version is still
     * $expectedVersion.
     *
     * @param  array<string, scalar|null>  $columns
     */
    public function updateIfVersion(string $categoryId, int $expectedVersion, array $columns, int $changeSeq): bool;

    /**
     * The person's live categories (archived included), one page plus one.
     *
     * @param  'name'|'created_at'  $sort
     * @param  array{value: string, id: string}|null  $after
     * @return list<CategoryRecord>
     */
    public function listOwned(string $userId, string $sort, bool $descending, ?array $after, int $limit): array;

    /**
     * The sort value a cursor carries for a category.
     *
     * @param  'name'|'created_at'  $sort
     */
    public function sortValue(CategoryRecord $category, string $sort): string;

    /**
     * Every platform category, retired included, in position order.
     *
     * @return list<PlatformCategoryRecord>
     */
    public function allPlatform(): array;
}

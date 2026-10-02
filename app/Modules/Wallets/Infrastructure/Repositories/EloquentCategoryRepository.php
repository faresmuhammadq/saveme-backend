<?php

namespace App\Modules\Wallets\Infrastructure\Repositories;

use App\Modules\Wallets\Domain\Categories\CategoryKind;
use App\Modules\Wallets\Domain\Categories\CategoryRecord;
use App\Modules\Wallets\Domain\Categories\PlatformCategoryRecord;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;

final class EloquentCategoryRepository implements CategoryRepository
{
    public function findAny(string $categoryId): ?CategoryRecord
    {
        $category = Category::query()->whereKey($categoryId)->whereNotNull('user_id')->first();

        return $category === null ? null : self::record($category);
    }

    public function findOwnedForUpdate(string $categoryId, string $ownerId): ?CategoryRecord
    {
        $category = Category::query()->whereKey($categoryId)->where('user_id', $ownerId)->lockForUpdate()->first();

        return $category === null ? null : self::record($category);
    }

    public function findForShare(string $categoryId): ?CategoryRecord
    {
        $category = Category::query()->whereKey($categoryId)->whereNotNull('user_id')->sharedLock()->first();

        return $category === null ? null : self::record($category);
    }

    public function findPlatformForShare(string $categoryId): ?PlatformCategoryRecord
    {
        $category = Category::query()->whereKey($categoryId)->whereNull('user_id')->sharedLock()->first();

        return $category === null ? null : self::platformRecord($category);
    }

    public function isHeldByOther(string $categoryId, string $ownerId): bool
    {
        return Category::query()
            ->whereKey($categoryId)
            ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhere('user_id', '<>', $ownerId))
            ->exists()
            || Wallet::query()->whereKey($categoryId)->exists()
            || CategoryReference::query()->whereKey($categoryId)->exists();
    }

    public function insert(string $categoryId, string $ownerId, array $values, int $changeSeq): void
    {
        Category::query()->forceCreate([
            'id' => $categoryId,
            'user_id' => $ownerId,
            ...$values,
            'version' => 1,
            'change_seq' => $changeSeq,
        ]);
    }

    public function updateIfVersion(string $categoryId, int $expectedVersion, array $columns, int $changeSeq): bool
    {
        return Category::query()
            ->whereKey($categoryId)
            ->where('version', $expectedVersion)
            ->toBase()
            ->update([
                ...$columns,
                'version' => $expectedVersion + 1,
                'change_seq' => $changeSeq,
                'updated_at' => now()->format('Y-m-d H:i:s.uP'),
            ]) === 1;
    }

    public function listOwned(string $userId, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $column = $sort === 'name' ? 'name COLLATE "C"' : 'created_at';
        $cast = $sort === 'name' ? '?::text' : '?::timestamptz';
        $direction = $descending ? 'DESC' : 'ASC';

        $query = Category::query()->where('user_id', $userId)->whereNull('deleted_at');

        if ($after !== null) {
            $query->whereRaw("({$column}, id) ".($descending ? '<' : '>')." ({$cast}, ?::uuid)", [$after['value'], $after['id']]);
        }

        return array_values($query
            ->orderByRaw("{$column} {$direction}, id {$direction}")
            ->limit($limit + 1)
            ->toBase()
            ->get()
            ->map(fn (object $row): CategoryRecord => self::rawRecord($row)) // @phpstan-ignore argument.type
            ->all());
    }

    public function sortValue(CategoryRecord $category, string $sort): string
    {
        return $sort === 'name'
            ? $category->name
            : $category->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    public function allPlatform(): array
    {
        return array_values(Category::query()
            ->whereNull('user_id')
            ->orderBy('position')
            ->get()
            ->map(fn (Category $category): PlatformCategoryRecord => self::platformRecord($category))
            ->all());
    }

    private static function record(Category $category): CategoryRecord
    {
        return new CategoryRecord(
            id: $category->id,
            ownerId: (string) $category->user_id,
            kind: CategoryKind::from($category->kind),
            name: (string) $category->name,
            icon: $category->icon,
            color: $category->color,
            archivedAt: $category->archived_at?->toDateTimeImmutable(),
            version: $category->version,
            changeSeq: (int) $category->change_seq,
            createdAt: $category->created_at->toDateTimeImmutable(),
            updatedAt: $category->updated_at->toDateTimeImmutable(),
            deletedAt: $category->deleted_at?->toDateTimeImmutable(),
        );
    }

    /**
     * A record from a plain row: a page of 200 categories skips Eloquent's
     * per-row hydration and date casting (SC-007).
     *
     * @param  object{id: string, user_id: string, kind: string, name: string, icon: ?string, color: ?string, archived_at: ?string, version: int|string, change_seq: int|string, created_at: string, updated_at: string, deleted_at: ?string}  $row
     */
    private static function rawRecord(object $row): CategoryRecord
    {
        return new CategoryRecord(
            id: $row->id,
            ownerId: (string) $row->user_id,
            kind: CategoryKind::from($row->kind),
            name: (string) $row->name,
            icon: $row->icon,
            color: $row->color,
            archivedAt: $row->archived_at === null ? null : new DateTimeImmutable($row->archived_at),
            version: (int) $row->version,
            changeSeq: (int) $row->change_seq,
            createdAt: new DateTimeImmutable($row->created_at),
            updatedAt: new DateTimeImmutable($row->updated_at),
            deletedAt: $row->deleted_at === null ? null : new DateTimeImmutable($row->deleted_at),
        );
    }

    private static function platformRecord(Category $category): PlatformCategoryRecord
    {
        $names = (array) $category->names;

        return new PlatformCategoryRecord(
            id: $category->id,
            systemKey: (string) $category->system_key,
            kind: CategoryKind::from($category->kind),
            names: ['ar' => (string) ($names['ar'] ?? ''), 'en' => (string) ($names['en'] ?? '')],
            icon: (string) $category->icon,
            color: (string) $category->color,
            position: (int) $category->position,
            archivedAt: $category->archived_at?->toDateTimeImmutable(),
            updatedAt: $category->updated_at->toDateTimeImmutable(),
        );
    }
}

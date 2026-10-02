<?php

namespace App\Modules\Wallets\Application\Categories;

use App\Modules\Wallets\Application\Categories\Dto\CategoryView;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryNotFound;

/**
 * Reads a person's own categories, each with whether it is in use. `in_use`
 * is answered set-wise for a page, never per row.
 */
final class CategoryQueryService
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly CategoryUsageQuery $usage,
    ) {}

    /**
     * The person's live categories, archived included.
     *
     * @param  'name'|'created_at'  $sort
     * @param  array{value: string, id: string}|null  $after
     * @return array{items: list<CategoryView>, next: array{value: string, id: string}|null}
     */
    public function listFor(string $userId, string $sort, bool $descending, ?array $after, int $limit): array
    {
        $rows = $this->categories->listOwned($userId, $sort, $descending, $after, $limit);
        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);

        $next = null;
        if ($hasMore && $page !== []) {
            $last = $page[array_key_last($page)];
            $next = ['value' => $this->categories->sortValue($last, $sort), 'id' => $last->id];
        }

        $used = $this->usage->usedAmong(array_map(fn ($category): string => $category->id, $page));

        return [
            'items' => array_map(fn ($category): CategoryView => new CategoryView($category, $used[$category->id] ?? false), $page),
            'next' => $next,
        ];
    }

    /**
     * One of the caller's own categories, a tombstone included.
     *
     * @throws CategoryNotFound
     */
    public function find(string $userId, string $categoryId): CategoryView
    {
        $category = $this->categories->findAny($categoryId);

        if ($category === null || $category->ownerId !== $userId) {
            throw new CategoryNotFound;
        }

        return new CategoryView($category, $this->usage->isUsed($categoryId));
    }
}

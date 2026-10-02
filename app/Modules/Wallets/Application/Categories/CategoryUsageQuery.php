<?php

namespace App\Modules\Wallets\Application\Categories;

/**
 * Asks every registered participant whether a category is in use. With no
 * participant (until B6) nothing is in use.
 */
final class CategoryUsageQuery
{
    /**
     * @param  iterable<CategoryUsage>  $participants
     */
    public function __construct(private readonly iterable $participants = []) {}

    public function isUsed(string $categoryId): bool
    {
        foreach ($this->participants as $participant) {
            if ($participant->isUsed($categoryId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $categoryIds
     * @return array<string, bool> id => in use, for every id given
     */
    public function usedAmong(array $categoryIds): array
    {
        $used = array_fill_keys($categoryIds, false);

        foreach ($this->participants as $participant) {
            foreach ($participant->usedAmong($categoryIds) as $id) {
                $used[$id] = true;
            }
        }

        return $used;
    }
}

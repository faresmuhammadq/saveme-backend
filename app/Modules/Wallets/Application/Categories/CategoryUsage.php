<?php

namespace App\Modules\Wallets\Application\Categories;

/**
 * A module that keeps records referencing a category registers one of these
 * (tagged with this interface's name) so "in use" is answered without
 * Wallets knowing its tables (spec 011, R-7): B6 transactions and postings,
 * live and tombstoned, B7 recurring rules, B8 budgets. A category any
 * participant calls used is never deleted; archive it instead.
 *
 * Answer set-wise: usedAmong() is called with a whole page of categories,
 * so an implementation must not query once per id.
 */
interface CategoryUsage
{
    public function isUsed(string $categoryId): bool;

    /**
     * Which of the given categories are used.
     *
     * @param  list<string>  $categoryIds
     * @return list<string> the ids that are used
     */
    public function usedAmong(array $categoryIds): array;
}

<?php

namespace App\Modules\Budgeting\Application;

use App\Modules\Budgeting\Domain\Ports\BudgetRepository;
use App\Modules\Wallets\Application\Categories\CategoryUsage;

/**
 * A category any budget, live or tombstoned, names is in use (spec 015,
 * FR-018): the person cannot delete it, only archive it. Answered set-wise
 * in one query.
 */
final class BudgetCategoryUsage implements CategoryUsage
{
    public function __construct(private readonly BudgetRepository $budgets) {}

    public function isUsed(string $categoryId): bool
    {
        return $this->budgets->categoryUsed($categoryId);
    }

    public function usedAmong(array $categoryIds): array
    {
        return $this->budgets->categoriesUsedAmong($categoryIds);
    }
}

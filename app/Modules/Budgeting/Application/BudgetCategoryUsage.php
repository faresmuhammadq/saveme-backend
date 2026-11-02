<?php

namespace App\Modules\Budgeting\Application;

use App\Modules\Budgeting\Domain\Ports\BudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Wallets\Application\Categories\CategoryUsage;

/**
 * A category any budget or Shared Budget line, live or tombstoned, names is
 * in use (spec 015, FR-018; ADR-0027 rule 11): the person cannot delete it,
 * only archive it. Answered set-wise.
 */
final class BudgetCategoryUsage implements CategoryUsage
{
    public function __construct(
        private readonly BudgetRepository $budgets,
        private readonly SharedBudgetRepository $sharedBudgets,
    ) {}

    public function isUsed(string $categoryId): bool
    {
        return $this->budgets->categoryUsed($categoryId) || $this->sharedBudgets->categoryUsed($categoryId);
    }

    public function usedAmong(array $categoryIds): array
    {
        return array_values(array_unique([...$this->budgets->categoriesUsedAmong($categoryIds), ...$this->sharedBudgets->categoriesUsedAmong($categoryIds)]));
    }
}

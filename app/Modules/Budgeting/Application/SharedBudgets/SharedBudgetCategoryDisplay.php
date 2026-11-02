<?php

namespace App\Modules\Budgeting\Application\SharedBudgets;

use App\Modules\Wallets\Application\Categories\CategoryDisplayParticipant;

/**
 * Budgeting's side of a category rename: every live Shared Budget line naming
 * the category carries the new display fields, so members who do not own the
 * category still render it (ADR-0027 rule 11).
 */
final class SharedBudgetCategoryDisplay implements CategoryDisplayParticipant
{
    public function __construct(private readonly SharedBudgetService $budgets) {}

    public function republish(string $categoryId, string $name, ?string $icon, ?string $color): void
    {
        $this->budgets->republishCategory($categoryId, $name, $icon, $color);
    }
}

<?php

namespace App\Modules\Wallets\Application\Recurring;

use App\Modules\Wallets\Application\Categories\CategoryUsage;
use App\Modules\Wallets\Domain\Recurring\Ports\OccurrenceRepository;
use App\Modules\Wallets\Domain\Recurring\Ports\RecurringRuleRepository;

/**
 * A category any rule (category or payout category) or occurrence references,
 * live or tombstoned, is in use (spec 014, FR-027): the person cannot delete
 * it, only archive it. Answered set-wise, one query per table.
 */
final class RecurringCategoryUsage implements CategoryUsage
{
    public function __construct(
        private readonly RecurringRuleRepository $rules,
        private readonly OccurrenceRepository $occurrences,
    ) {}

    public function isUsed(string $categoryId): bool
    {
        return $this->rules->categoryUsed($categoryId) || $this->occurrences->categoryUsed($categoryId);
    }

    public function usedAmong(array $categoryIds): array
    {
        return array_values(array_unique([
            ...$this->rules->categoriesUsedAmong($categoryIds),
            ...$this->occurrences->categoriesUsedAmong($categoryIds),
        ]));
    }
}

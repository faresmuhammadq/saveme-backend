<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * The by-category arithmetic of a Report (spec 019, FR-011, FR-012,
 * research R-2 and R-7). An item exists when its actual sum (or, when
 * planned figures are asked for, its planned sum) is non-zero. Categories are
 * grouped by id and never merged by name. No share is computed: ADR-0003
 * rule 7 allows no division of an amount.
 */
final readonly class CategoryBreakdown
{
    public function __construct(
        public CategoryGroup $expenses,
        public CategoryGroup $income,
    ) {}

    /**
     * @param  list<CategoryTotal>  $actual  expense and income category sums, both positive
     * @param  list<CategoryTotal>|null  $planned  null unless planned figures were asked for
     */
    public static function from(array $actual, ?array $planned, string $currency): self
    {
        return new self(
            self::group(LegTotal::EXPENSE, $actual, $planned, $currency),
            self::group(LegTotal::INCOME, $actual, $planned, $currency),
        );
    }

    /**
     * @param  list<CategoryTotal>  $actual
     * @param  list<CategoryTotal>|null  $planned
     */
    private static function group(string $kind, array $actual, ?array $planned, string $currency): CategoryGroup
    {
        $zero = Money::of(0, $currency);
        $byCategory = [];

        foreach ($actual as $row) {
            if ($row->kind === $kind) {
                $byCategory[$row->categoryId] = ['systemKey' => $row->systemKey, 'value' => ($byCategory[$row->categoryId]['value'] ?? $zero)->plus($row->amount), 'planned' => $byCategory[$row->categoryId]['planned'] ?? $zero];
            }
        }

        foreach ($planned ?? [] as $row) {
            if ($row->kind === $kind) {
                $entry = $byCategory[$row->categoryId] ?? ['systemKey' => $row->systemKey, 'value' => $zero, 'planned' => $zero];
                $entry['planned'] = $entry['planned']->plus($row->amount);
                $byCategory[$row->categoryId] = $entry;
            }
        }

        $items = [];
        $total = $zero;
        $plannedTotal = $zero;

        foreach ($byCategory as $categoryId => $entry) {
            if ($entry['value']->isZero() && $entry['planned']->isZero()) {
                continue;
            }

            $categoryId = (string) $categoryId;
            $items[] = new CategoryItem($entry['systemKey'] ?? $categoryId, $categoryId, $entry['systemKey'], $entry['value'], $planned === null ? null : $entry['planned']);
            $total = $total->plus($entry['value']);
            $plannedTotal = $plannedTotal->plus($entry['planned']);
        }

        usort($items, fn (CategoryItem $a, CategoryItem $b): int => $b->value->amountMinor <=> $a->value->amountMinor ?: strcmp($a->label, $b->label));

        return new CategoryGroup($total, $planned === null ? null : $plannedTotal, $items);
    }
}

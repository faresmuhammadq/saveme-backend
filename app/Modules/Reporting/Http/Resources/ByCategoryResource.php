<?php

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Application\Dto\CategoryReport;
use App\Modules\Reporting\Domain\CategoryGroup;
use App\Modules\Reporting\Domain\CategoryItem;

/**
 * A by-category Report on the wire (ReportByCategory). No share member exists.
 */
final class ByCategoryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(CategoryReport $report): array
    {
        return [
            'wallet_id' => $report->walletId,
            'range' => ['from' => $report->range->from, 'to' => $report->range->to],
            'currency' => $report->currency,
            'expenses' => self::group($report->breakdown->expenses),
            'income' => self::group($report->breakdown->income),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function group(CategoryGroup $group): array
    {
        return [
            'total' => ReportMoney::of($group->total),
            'planned' => $group->planned === null ? null : ReportMoney::of($group->planned),
            'items' => array_map(fn (CategoryItem $item): array => [
                'label' => $item->label,
                'category_id' => $item->categoryId,
                'system_key' => $item->systemKey,
                'value' => ReportMoney::of($item->value),
                'planned' => $item->planned === null ? null : ReportMoney::of($item->planned),
            ], $group->items),
        ];
    }
}

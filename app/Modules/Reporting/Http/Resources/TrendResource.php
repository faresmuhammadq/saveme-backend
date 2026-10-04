<?php

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Application\Dto\TrendReport;
use App\Modules\Reporting\Domain\TrendBucket;

/**
 * A trend Report on the wire (ReportTrend): the Budget Periods and one point
 * per period in every series.
 */
final class TrendResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(TrendReport $report): array
    {
        $series = $report->series;
        $labels = array_map(fn (TrendBucket $bucket): string => $bucket->label(), $series->buckets);

        return [
            'wallet_id' => $report->walletId,
            'currency' => $report->currency,
            'periods' => array_map(fn (TrendBucket $bucket): array => ['label' => $bucket->label(), 'from' => $bucket->from, 'to' => $bucket->to], $series->buckets),
            'income' => ReportMoney::series($series->income, $labels),
            'expenses' => ReportMoney::series($series->expenses, $labels),
            'net' => ReportMoney::series($series->net, $labels),
            'planned_income' => $series->plannedIncome === null ? null : ReportMoney::series($series->plannedIncome, $labels),
            'planned_expenses' => $series->plannedExpenses === null ? null : ReportMoney::series($series->plannedExpenses, $labels),
        ];
    }
}

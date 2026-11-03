<?php

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Application\Dto\SummaryReport;

/**
 * A summary Report on the wire (docs/openapi/paths/reports.yaml, ReportSummary).
 */
final class SummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SummaryReport $report): array
    {
        $figures = $report->figures;

        return [
            'wallet_id' => $report->walletId,
            'range' => ['from' => $report->range->from, 'to' => $report->range->to],
            'currency' => $report->currency,
            'income' => ReportMoney::of($figures->income),
            'expenses' => ReportMoney::of($figures->expenses),
            'net' => ReportMoney::of($figures->net),
            'transfers_in' => ReportMoney::of($figures->transfersIn),
            'transfers_out' => ReportMoney::of($figures->transfersOut),
            'adjustments_in' => ReportMoney::of($figures->adjustmentsIn),
            'adjustments_out' => ReportMoney::of($figures->adjustmentsOut),
            'balance_start' => ReportMoney::of($figures->balanceStart),
            'balance_end' => ReportMoney::of($figures->balanceEnd),
            'planned' => $report->planned === null ? null : [
                'income' => ReportMoney::of($report->planned->income),
                'expenses' => ReportMoney::of($report->planned->expenses),
                'transfers_in' => ReportMoney::of($report->planned->transfersIn),
                'transfers_out' => ReportMoney::of($report->planned->transfersOut),
            ],
        ];
    }
}

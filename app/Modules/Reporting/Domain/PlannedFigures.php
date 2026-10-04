<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * Folds scheduled occurrences into the shapes of the actual figures (spec 019,
 * FR-014, research R-6): a summary, category totals and daily totals. They
 * are always separate from the actual figures. A transfer occurrence between
 * two covered wallets is dropped, as an actual internal transfer is.
 */
final class PlannedFigures
{
    /**
     * @param  list<ScheduledTotal>  $rows
     * @param  list<string>  $coveredWalletIds
     */
    public static function summary(array $rows, array $coveredWalletIds, string $currency): PlannedSummary
    {
        $covered = array_flip($coveredWalletIds);
        $income = $expenses = $in = $out = Money::of(0, $currency);

        foreach ($rows as $row) {
            if ($row->kind === LegTotal::INCOME) {
                $income = $income->plus($row->amount);
            } elseif ($row->kind === LegTotal::EXPENSE) {
                $expenses = $expenses->plus($row->amount);
            } else {
                $from = isset($covered[$row->walletId]);
                $to = $row->counterWalletId !== null && isset($covered[$row->counterWalletId]);

                if ($from && ! $to) {
                    $out = $out->plus($row->amount);
                } elseif ($to && ! $from) {
                    $in = $in->plus($row->amount);
                }
            }
        }

        return new PlannedSummary($income, $expenses, $in, $out);
    }

    /**
     * The category totals of the income and expense occurrences.
     *
     * @param  list<ScheduledTotal>  $rows
     * @return list<CategoryTotal>
     */
    public static function categories(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            if ($row->categoryId === null || $row->kind === LegTotal::TRANSFER) {
                continue;
            }

            $key = $row->kind.'|'.$row->categoryId;
            $totals[$key] = isset($totals[$key])
                ? new CategoryTotal($row->kind, $row->categoryId, $row->systemKey, $totals[$key]->amount->plus($row->amount))
                : new CategoryTotal($row->kind, $row->categoryId, $row->systemKey, $row->amount);
        }

        return array_values($totals);
    }

    /**
     * Income and expense occurrences as daily totals, signed like ledger legs.
     *
     * @param  list<ScheduledTotal>  $rows
     * @return list<DailyTotal>
     */
    public static function daily(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            if ($row->kind === LegTotal::TRANSFER) {
                continue;
            }

            $totals[] = new DailyTotal($row->date, $row->kind, $row->kind === LegTotal::EXPENSE ? $row->amount->negate() : $row->amount);
        }

        return $totals;
    }
}

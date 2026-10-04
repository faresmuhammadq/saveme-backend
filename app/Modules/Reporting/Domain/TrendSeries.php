<?php

namespace App\Modules\Reporting\Domain;

use App\Modules\Shared\Domain\Money\Money;

/**
 * The trend arithmetic of a Report (spec 019, FR-013): daily wallet-leg
 * totals bucketed into consecutive Budget Periods, zero-filled, in the order
 * of the buckets. `net` is income minus expenses per bucket. Planned series
 * are present only when planned figures were asked for.
 */
final readonly class TrendSeries
{
    /**
     * Each series is one Money per bucket, in bucket order.
     *
     * @param  list<TrendBucket>  $buckets
     * @param  list<Money>  $income
     * @param  list<Money>  $expenses
     * @param  list<Money>  $net
     * @param  list<Money>|null  $plannedIncome
     * @param  list<Money>|null  $plannedExpenses
     */
    private function __construct(
        public array $buckets,
        public array $income,
        public array $expenses,
        public array $net,
        public ?array $plannedIncome,
        public ?array $plannedExpenses,
    ) {}

    /**
     * @param  list<TrendBucket>  $buckets  oldest first
     * @param  list<DailyTotal>  $actual
     * @param  list<DailyTotal>|null  $planned  null unless planned figures were asked for
     */
    public static function bucket(array $buckets, array $actual, ?array $planned, string $currency): self
    {
        [$income, $expenses] = self::sums($buckets, $actual, $currency);
        $net = array_map(fn (Money $earned, Money $spent): Money => $earned->minus($spent), $income, $expenses);
        [$plannedIncome, $plannedExpenses] = $planned === null ? [null, null] : self::sums($buckets, $planned, $currency);

        return new self($buckets, $income, $expenses, $net, $plannedIncome, $plannedExpenses);
    }

    /**
     * @param  list<TrendBucket>  $buckets
     * @param  list<DailyTotal>  $rows
     * @return array{0: list<Money>, 1: list<Money>}
     */
    private static function sums(array $buckets, array $rows, string $currency): array
    {
        $income = [];
        $expenses = [];

        foreach ($buckets as $bucket) {
            $earned = $spent = Money::of(0, $currency);

            foreach ($rows as $row) {
                if (! $bucket->contains($row->date)) {
                    continue;
                }

                if ($row->kind === LegTotal::INCOME) {
                    $earned = $earned->plus($row->amount);
                } elseif ($row->kind === LegTotal::EXPENSE) {
                    $spent = $spent->minus($row->amount);
                }
            }

            $income[] = $earned;
            $expenses[] = $spent;
        }

        return [$income, $expenses];
    }
}

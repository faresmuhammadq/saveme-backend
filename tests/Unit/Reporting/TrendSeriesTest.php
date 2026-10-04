<?php

use App\Modules\Reporting\Domain\DailyTotal;
use App\Modules\Reporting\Domain\TrendBucket;
use App\Modules\Reporting\Domain\TrendSeries;
use App\Modules\Shared\Domain\Money\Money;

/*
 * The trend arithmetic (spec 019, FR-013): daily totals bucketed into Budget
 * Periods, zero-filled, in the order of the buckets.
 */

function day(string $date, string $kind, int $minor): DailyTotal
{
    return new DailyTotal($date, $kind, Money::of($minor, 'EGP'));
}

function minor(array $series): array
{
    return array_map(fn (Money $money): int => $money->amountMinor, $series);
}

beforeEach(function () {
    $this->buckets = [
        new TrendBucket('2026-08-01', '2026-08-31'),
        new TrendBucket('2026-09-01', '2026-09-30'),
        new TrendBucket('2026-10-01', '2026-10-31'),
    ];
});

test('rows land in the bucket containing their date, edges inclusive', function () {
    $series = TrendSeries::bucket($this->buckets, [
        day('2026-08-31', 'expense', -100),
        day('2026-09-01', 'expense', -200),
        day('2026-09-30', 'income', 1000),
        day('2026-10-01', 'income', 50),
    ], null, 'EGP');

    expect(minor($series->expenses))->toBe([100, 200, 0])
        ->and(minor($series->income))->toBe([0, 1000, 50]);
});

test('empty buckets are zero-filled and net is income minus expenses', function () {
    $series = TrendSeries::bucket($this->buckets, [day('2026-09-10', 'income', 900), day('2026-09-11', 'expense', -400)], null, 'EGP');

    expect(minor($series->income))->toBe([0, 900, 0])
        ->and(minor($series->expenses))->toBe([0, 400, 0])
        ->and(minor($series->net))->toBe([0, 500, 0]);
});

test('series follow the order of the buckets and labels are start dates', function () {
    $series = TrendSeries::bucket($this->buckets, [], null, 'EGP');

    expect(array_map(fn (TrendBucket $bucket): string => $bucket->label(), $series->buckets))->toBe(['2026-08-01', '2026-09-01', '2026-10-01'])
        ->and(count($series->income))->toBe(3)
        ->and($series->plannedIncome)->toBeNull()
        ->and($series->plannedExpenses)->toBeNull();
});

test('planned series exist only when planned rows are given, and never change the actual ones', function () {
    $actual = [day('2026-09-10', 'expense', -400)];
    $planned = [day('2026-10-20', 'expense', -450000), day('2026-10-25', 'income', 1000)];

    $with = TrendSeries::bucket($this->buckets, $actual, $planned, 'EGP');
    $without = TrendSeries::bucket($this->buckets, $actual, null, 'EGP');

    expect(minor($with->plannedExpenses ?? []))->toBe([0, 0, 450000])
        ->and(minor($with->plannedIncome ?? []))->toBe([0, 0, 1000])
        ->and(minor($with->expenses))->toBe(minor($without->expenses))
        ->and(minor(TrendSeries::bucket($this->buckets, $actual, [], 'EGP')->plannedIncome ?? []))->toBe([0, 0, 0]);
});

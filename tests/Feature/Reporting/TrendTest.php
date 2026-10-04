<?php

use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Reporting\Domain\TrendSeries;
use Illuminate\Support\Facades\DB;

/*
 * The trend report (spec 019, US3 S1-S4, FR-013): consecutive Budget Periods,
 * oldest first, zero-filled, one daily-totals query per request.
 */

beforeEach(function () {
    freezeClock('2026-10-04 10:00:00');
    $this->owner = person('owner@example.test');
    $this->wallet = ownedWallet($this->owner->id);
});

function trendOf(object $test, array $request = []): TrendSeries
{
    return app(ReportQueryService::class)->walletTrend($test->owner->id, $test->wallet->id, new ReportRequest(...$request))->series;
}

function seriesMinor(array $series): array
{
    return array_map(fn ($money): int => $money->amountMinor, $series);
}

test('the default is six periods ending with today\'s, empty months zero-filled', function () {
    spend($this->owner->id, $this->wallet->id, 1000, ['date' => '2026-05-10']);
    recordTransaction($this->owner->id, $this->wallet->id, incomeBody(['amount_minor' => 5000, 'date' => '2026-06-30']));
    spend($this->owner->id, $this->wallet->id, 300, ['date' => '2026-10-01']);
    spend($this->owner->id, $this->wallet->id, 99, ['date' => '2026-04-30']);
    spend($this->owner->id, $this->wallet->id, 77, ['date' => '2026-11-01']);

    $series = trendOf($this);

    expect(array_map(fn ($bucket): string => $bucket->label(), $series->buckets))->toBe(['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'])
        ->and(seriesMinor($series->expenses))->toBe([1000, 0, 0, 0, 0, 300])
        ->and(seriesMinor($series->income))->toBe([0, 5000, 0, 0, 0, 0])
        ->and(seriesMinor($series->net))->toBe([-1000, 5000, 0, 0, 0, -300]);
});

test('months and date pick twelve periods ending with the one containing the date', function () {
    $series = trendOf($this, ['months' => 12, 'date' => '2026-03-15']);

    expect($series->buckets)->toHaveCount(12)
        ->and($series->buckets[0]->from)->toBe('2025-04-01')
        ->and($series->buckets[11]->to)->toBe('2026-03-31');
});

test('with start day 25 the buckets start on the 25th and a start-day change gives the budget progress periods', function () {
    $credential = signInAs('owner@example.test')->json('data.credential');
    $budget = createBudget($this->owner->id);
    saveProfile($credential, profileBody(['budget_period_start_day' => 25]))->assertOk();
    spend($this->owner->id, $this->wallet->id, 500, ['date' => '2026-10-24']);
    spend($this->owner->id, $this->wallet->id, 800, ['date' => '2026-10-25']);

    $series = trendOf($this, ['months' => 4, 'date' => '2026-11-30']);
    $progress = fn (string $date): string => ($p = budgetProgress($this->owner->id, $budget->id, $date)->period)->start.'..'.$p->end;

    expect(array_map(fn ($bucket): string => $bucket->from.'..'.$bucket->to, $series->buckets))
        ->toBe(['2026-09-01..2026-09-30', '2026-10-01..2026-10-24', '2026-10-25..2026-11-24', '2026-11-25..2026-12-24'])
        ->and($series->buckets[1]->from.'..'.$series->buckets[1]->to)->toBe($progress('2026-10-12'))
        ->and(seriesMinor($series->expenses))->toBe([0, 500, 800, 0]);
});

test('transfers are not in the trend', function () {
    recordTransaction($this->owner->id, $this->wallet->id, transferBody(ownedWallet($this->owner->id)->id, ['amount_minor' => 4000, 'date' => '2026-10-02']));

    $series = trendOf($this);

    expect(seriesMinor($series->income))->each->toBe(0)->and(seriesMinor($series->expenses))->each->toBe(0);
});

test('one daily-totals query serves the whole request', function () {
    DB::enableQueryLog();
    trendOf($this, ['months' => 12]);
    $daily = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'GROUP BY t.date, t.kind'));
    DB::disableQueryLog();

    expect($daily)->toHaveCount(1);
});

<?php

use App\Modules\Budgeting\Application\BudgetPeriodQuery;
use App\Modules\Budgeting\Application\Dto\BudgetPeriodSpan;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;

/*
 * The shared Budget Period code path (spec 019, R-5; FR-002, FR-013): "today"
 * in the profile timezone, the period containing a date, and consecutive
 * periods ending with one. Budgets and reports both call it.
 */

function spans(array $spans): array
{
    return array_map(fn (BudgetPeriodSpan $span): string => $span->from.'..'.$span->to, $spans);
}

test('today follows the profile timezone and falls back to the configured default', function () {
    freezeClock('2026-10-31 22:30:00');
    $cairo = person('cairo@example.test');
    $utc = person('utc@example.test');
    Profile::query()->where('user_id', $utc->id)->update(['timezone' => 'UTC']);
    $bare = User::factory()->create();

    $query = app(BudgetPeriodQuery::class);

    expect($query->todayOf($cairo->id))->toBe('2026-11-01')
        ->and($query->todayOf($utc->id))->toBe('2026-10-31')
        ->and($query->todayOf($bare->id))->toBe(now()->setTimezone(config('identity.default_timezone'))->format('Y-m-d'));
});

test('the period containing a date equals what budget progress reports', function () {
    freezeClock('2026-10-02 10:00:00');
    $owner = person('owner@example.test');
    $credential = signInAs('owner@example.test')->json('data.credential');
    $budget = createBudget($owner->id);
    $query = app(BudgetPeriodQuery::class);

    expect(spans([$query->periodContaining($owner->id, '2026-10-12')]))->toBe(['2026-10-01..2026-10-31']);

    saveProfile($credential, profileBody(['budget_period_start_day' => 25]))->assertOk();

    foreach (['2026-10-12', '2026-10-30', '2026-09-15', '2026-12-25'] as $date) {
        $progress = budgetProgress($owner->id, $budget->id, $date)->period;
        $span = $query->periodContaining($owner->id, $date);

        expect($span->from.'..'.$span->to)->toBe($progress->start.'..'.$progress->end);
    }
});

test('periods ending with a date are consecutive and oldest first', function () {
    freezeClock('2026-10-04 10:00:00');
    $owner = person('owner@example.test');
    $query = app(BudgetPeriodQuery::class);

    expect(spans($query->periodsEndingWith($owner->id, '2026-10-04', 6)))->toBe([
        '2026-05-01..2026-05-31', '2026-06-01..2026-06-30', '2026-07-01..2026-07-31',
        '2026-08-01..2026-08-31', '2026-09-01..2026-09-30', '2026-10-01..2026-10-31',
    ])->and(spans($query->periodsEndingWith($owner->id, '2026-10-04', 1)))->toBe(['2026-10-01..2026-10-31']);
});

test('with start day 25 the periods start on the 25th, and the transitional period appears once', function () {
    freezeClock('2026-10-02 10:00:00');
    $owner = person('owner@example.test');
    $credential = signInAs('owner@example.test')->json('data.credential');
    saveProfile($credential, profileBody(['budget_period_start_day' => 25]))->assertOk();

    $query = app(BudgetPeriodQuery::class);

    expect(spans($query->periodsEndingWith($owner->id, '2026-11-30', 4)))->toBe([
        '2026-09-01..2026-09-30', '2026-10-01..2026-10-24', '2026-10-25..2026-11-24', '2026-11-25..2026-12-24',
    ]);
});

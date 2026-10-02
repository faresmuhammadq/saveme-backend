<?php

use App\Modules\Budgeting\Domain\BudgetPeriod;
use App\Modules\Budgeting\Domain\BudgetPeriods;
use App\Modules\Budgeting\Domain\StartDayEntry;

/*
 * Budget Period arithmetic (spec 015, FR-008, US5 S1-S3): periods already
 * begun keep their dates after a start day change.
 */

function budgetPeriodOf(string $date, array $entries = [], int $fallback = 1): string
{
    $period = BudgetPeriods::containing($date, array_map(fn (array $e) => new StartDayEntry($e[0], $e[1]), $entries), $fallback);

    return $period->start.'..'.$period->end;
}

test('with no history the periods are calendar months', function () {
    expect(budgetPeriodOf('2026-10-02'))->toBe('2026-10-01..2026-10-31')
        ->and(budgetPeriodOf('2026-10-31'))->toBe('2026-10-01..2026-10-31')
        ->and(budgetPeriodOf('2026-02-15'))->toBe('2026-02-01..2026-02-28')
        ->and(budgetPeriodOf('2028-02-15'))->toBe('2028-02-01..2028-02-29');
});

test('an implicit entry takes the fallback day', function () {
    expect(budgetPeriodOf('2026-10-02', [], 25))->toBe('2026-09-25..2026-10-24')
        ->and(budgetPeriodOf('2026-10-25', [[25, null]]))->toBe('2026-10-25..2026-11-24')
        ->and(budgetPeriodOf('2026-10-24', [[25, null]]))->toBe('2026-09-25..2026-10-24');
});

test('a change leaves the period under way and the earlier ones as they were', function () {
    $history = [[1, null], [25, '2026-10-25']];

    expect(budgetPeriodOf('2026-10-12', $history))->toBe('2026-10-01..2026-10-24')
        ->and(budgetPeriodOf('2026-10-30', $history))->toBe('2026-10-25..2026-11-24')
        ->and(budgetPeriodOf('2026-09-15', $history))->toBe('2026-09-01..2026-09-30')
        ->and(budgetPeriodOf('2026-10-24', $history))->toBe('2026-10-01..2026-10-24')
        ->and(budgetPeriodOf('2026-10-25', $history))->toBe('2026-10-25..2026-11-24');
});

test('a transitional period may be longer than a month', function () {
    expect(budgetPeriodOf('2026-10-30', [[25, null], [10, '2026-11-10']]))->toBe('2026-10-25..2026-11-09')
        ->and(budgetPeriodOf('2026-11-10', [[25, null], [10, '2026-11-10']]))->toBe('2026-11-10..2026-12-09');
});

test('a transitional period may be shorter than a month', function () {
    expect(budgetPeriodOf('2026-10-02', [[1, null], [20, '2026-10-20']]))->toBe('2026-10-01..2026-10-19');
});

test('the year boundary', function () {
    expect(budgetPeriodOf('2026-12-30', [[25, null]]))->toBe('2026-12-25..2027-01-24')
        ->and(budgetPeriodOf('2027-01-10', [[25, null]]))->toBe('2026-12-25..2027-01-24');
});

test('February with the last allowed start day', function () {
    expect(budgetPeriodOf('2027-02-27', [[28, null]]))->toBe('2027-01-28..2027-02-27')
        ->and(budgetPeriodOf('2027-02-28', [[28, null]]))->toBe('2027-02-28..2027-03-27');
});

test('a date before the first dated entry uses the first entry unbounded', function () {
    expect(budgetPeriodOf('2020-05-17', [[1, null], [25, '2026-10-25']]))->toBe('2020-05-01..2020-05-31');
});

test('several changes keep every period distinct and gap-free', function () {
    $history = [[1, null], [25, '2026-10-25'], [5, '2027-01-05']];
    $date = '2026-09-01';
    $previousEnd = null;

    while ($date < '2027-04-01') {
        $period = BudgetPeriods::containing($date, array_map(fn (array $e) => new StartDayEntry($e[0], $e[1]), $history));

        expect($period->contains($date))->toBeTrue();

        if ($previousEnd !== null && $period->start !== $previousEnd) {
            throw new RuntimeException("Gap or overlap before {$period->start}");
        }

        $previousEnd = (new DateTimeImmutable($period->end))->modify('+1 day')->format('Y-m-d');
        $date = $previousEnd;
    }

    expect($previousEnd)->toBeString();
});

test('days in period, contains and days elapsed', function () {
    $period = new BudgetPeriod('2026-09-25', '2026-10-24');

    expect($period->daysInPeriod())->toBe(30)
        ->and($period->contains('2026-09-25'))->toBeTrue()
        ->and($period->contains('2026-10-24'))->toBeTrue()
        ->and($period->contains('2026-10-25'))->toBeFalse()
        ->and($period->daysElapsed('2026-09-25'))->toBe(1)
        ->and($period->daysElapsed('2026-10-02'))->toBe(8)
        ->and($period->daysElapsed('2026-10-24'))->toBe(30)
        ->and($period->daysElapsed('2026-10-25'))->toBeNull()
        ->and($period->daysElapsed('2026-09-24'))->toBeNull();
});

test('a start day outside 1 to 28 is refused', function () {
    new StartDayEntry(29, null);
})->throws(InvalidArgumentException::class);

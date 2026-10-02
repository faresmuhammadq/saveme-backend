<?php

use App\Modules\Budgeting\Domain\BudgetPeriod;
use App\Modules\Budgeting\Domain\BudgetProgress;
use App\Modules\Budgeting\Domain\BudgetRecord;
use App\Modules\Budgeting\Domain\PeriodType;
use App\Modules\Budgeting\Domain\SpendingLine;

/*
 * Budget Progress (spec 015, FR-012, FR-014, FR-015). No amount is divided
 * or rounded here: the projection goes through Money::allocate().
 */

const BUDGET_GROCERIES = '0192f7a1-0000-7000-8000-0000000000c5';
const BUDGET_DINING = '0192f7a1-0000-7000-8000-0000000000c6';

function aBudgetRecord(int $limit = 600000, ?string $category = BUDGET_GROCERIES, string $currency = 'EGP'): BudgetRecord
{
    $now = new DateTimeImmutable('2026-10-01T08:00:00Z');

    return new BudgetRecord('0192f7e0-0000-7000-8000-0000000000b1', '0192f79e-0000-7000-8000-000000000001', $category, PeriodType::Monthly, $limit, $currency, null, 1, 3, $now, $now, null);
}

function budgetProgressOf(BudgetRecord $budget, array $lines, string $today = '2026-10-02', string $start = '2026-09-25', string $end = '2026-10-24'): BudgetProgress
{
    return BudgetProgress::of($budget, new BudgetPeriod($start, $end), $lines, $today);
}

test('spent sums the lines of the budget category and currency', function () {
    $progress = budgetProgressOf(aBudgetRecord(), [
        new SpendingLine(BUDGET_GROCERIES, 'EGP', 100000),
        new SpendingLine(BUDGET_DINING, 'EGP', 999),
        new SpendingLine(BUDGET_GROCERIES, 'USD', 5000),
    ]);

    expect($progress->spent->amountMinor)->toBe(100000)
        ->and($progress->remaining->amountMinor)->toBe(500000)
        ->and($progress->limit->amountMinor)->toBe(600000);
});

test('an all-expenses budget sums every category in its currency', function () {
    $progress = budgetProgressOf(aBudgetRecord(category: null), [
        new SpendingLine(BUDGET_GROCERIES, 'EGP', 100000),
        new SpendingLine(BUDGET_DINING, 'EGP', 250000),
        new SpendingLine(BUDGET_DINING, 'USD', 10),
    ]);

    expect($progress->spent->amountMinor)->toBe(350000);
});

test('other currencies are named once, sorted, and never summed', function () {
    $progress = budgetProgressOf(aBudgetRecord(), [
        new SpendingLine(BUDGET_GROCERIES, 'USD', 5000),
        new SpendingLine(BUDGET_GROCERIES, 'EUR', 1),
        new SpendingLine(BUDGET_DINING, 'GBP', 7),
        new SpendingLine(BUDGET_GROCERIES, 'EGP', 100),
    ]);

    expect($progress->otherCurrencies)->toBe(['EUR', 'USD'])
        ->and($progress->spent->amountMinor)->toBe(100);
});

test('over the limit is strictly greater than the limit', function () {
    expect(budgetProgressOf(aBudgetRecord(1000), [new SpendingLine(BUDGET_GROCERIES, 'EGP', 1001)])->overLimit)->toBeTrue()
        ->and(budgetProgressOf(aBudgetRecord(1000), [new SpendingLine(BUDGET_GROCERIES, 'EGP', 1000)])->overLimit)->toBeFalse()
        ->and(budgetProgressOf(aBudgetRecord(1000), [new SpendingLine(BUDGET_GROCERIES, 'EGP', 999)])->overLimit)->toBeFalse();

    $over = budgetProgressOf(aBudgetRecord(600000), [new SpendingLine(BUDGET_GROCERIES, 'EGP', 650000)]);
    expect($over->remaining->amountMinor)->toBe(-50000);

    $at = budgetProgressOf(aBudgetRecord(1000), [new SpendingLine(BUDGET_GROCERIES, 'EGP', 1000)]);
    expect($at->remaining->amountMinor)->toBe(0);
});

test('no spending is zero and the whole limit remains', function () {
    $progress = budgetProgressOf(aBudgetRecord(), []);

    expect($progress->spent->amountMinor)->toBe(0)
        ->and($progress->remaining->amountMinor)->toBe(600000)
        ->and($progress->projectedEnd?->amountMinor)->toBe(0)
        ->and($progress->overLimit)->toBeFalse();
});

test('the projection on the first and last day', function () {
    $lines = [new SpendingLine(BUDGET_GROCERIES, 'EGP', 1000)];

    expect(budgetProgressOf(aBudgetRecord(), $lines, '2026-09-25')->projectedEnd?->amountMinor)->toBe(30000)
        ->and(budgetProgressOf(aBudgetRecord(), $lines, '2026-10-24')->projectedEnd?->amountMinor)->toBe(1000);
});

test('the projection rounds up to the minor unit', function () {
    expect(budgetProgressOf(aBudgetRecord(), [new SpendingLine(BUDGET_GROCERIES, 'EGP', 350000)], '2026-10-02')->projectedEnd?->amountMinor)->toBe(1312500);

    $thirtyOne = budgetProgressOf(aBudgetRecord(), [new SpendingLine(BUDGET_GROCERIES, 'EGP', 100001)], '2026-09-27', '2026-09-25', '2026-10-25');
    expect($thirtyOne->period->daysInPeriod())->toBe(31)
        ->and($thirtyOne->projectedEnd?->amountMinor)->toBe(1033344);
});

test('the projection exists for the current period only', function () {
    $lines = [new SpendingLine(BUDGET_GROCERIES, 'EGP', 1000)];

    $past = budgetProgressOf(aBudgetRecord(), $lines, '2026-11-15');
    $future = budgetProgressOf(aBudgetRecord(), $lines, '2026-09-01');

    expect($past->projectedEnd)->toBeNull()->and($past->isCurrent())->toBeFalse()->and($past->daysElapsed)->toBeNull()
        ->and($future->projectedEnd)->toBeNull()
        ->and(budgetProgressOf(aBudgetRecord(), $lines)->isCurrent())->toBeTrue();
});

test('a projection beyond 2^53 - 1 is null, not wrong', function () {
    $edge = intdiv(9007199254740991, 30);

    expect(budgetProgressOf(aBudgetRecord(), [new SpendingLine(BUDGET_GROCERIES, 'EGP', $edge)])->projectedEnd)->not->toBeNull()
        ->and(budgetProgressOf(aBudgetRecord(), [new SpendingLine(BUDGET_GROCERIES, 'EGP', $edge + 1)])->projectedEnd)->toBeNull();
});

test('a currency with three decimals keeps its minor units', function () {
    $progress = budgetProgressOf(aBudgetRecord(250000, BUDGET_GROCERIES, 'KWD'), [new SpendingLine(BUDGET_GROCERIES, 'KWD', 12345)], '2026-09-25');

    expect($progress->spent->amountMinor)->toBe(12345)
        ->and($progress->spent->currency->code)->toBe('KWD')
        ->and($progress->projectedEnd?->amountMinor)->toBe(370350);
});

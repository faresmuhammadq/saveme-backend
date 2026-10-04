<?php

use App\Modules\Reporting\Domain\Refusals\InvalidReportRange;
use App\Modules\Reporting\Domain\ReportRange;

/*
 * The Report Range rules (spec 019, FR-003, A-27).
 */

test('an explicit range holds both ends, inclusive', function () {
    $range = ReportRange::explicit('2026-09-01', '2026-09-30');

    expect($range->from)->toBe('2026-09-01')->and($range->to)->toBe('2026-09-30')
        ->and(ReportRange::explicit('2026-09-05', '2026-09-05')->to)->toBe('2026-09-05');
});

test('a range that ends before it starts is refused', function () {
    expect(fn () => ReportRange::explicit('2026-10-02', '2026-10-01'))->toThrow(InvalidReportRange::class);
});

test('366 days are accepted and 367 are refused', function () {
    expect(ReportRange::explicit('2024-01-01', '2024-12-31')->to)->toBe('2024-12-31')
        ->and(fn () => ReportRange::explicit('2026-01-01', '2027-01-02'))->toThrow(InvalidReportRange::class);
});

test('dates outside 1900-01-01 to 2999-12-31 are refused', function () {
    expect(fn () => ReportRange::explicit('1899-12-31', '1900-01-05'))->toThrow(InvalidReportRange::class)
        ->and(fn () => ReportRange::explicit('2999-12-25', '3000-01-01'))->toThrow(InvalidReportRange::class)
        ->and(ReportRange::explicit('1900-01-01', '1900-01-31')->from)->toBe('1900-01-01')
        ->and(ReportRange::explicit('2999-12-01', '2999-12-31')->to)->toBe('2999-12-31');
});

test('a Budget Period of any span is accepted as computed', function () {
    $range = ReportRange::ofBudgetPeriod('2026-01-01', '2027-06-30');

    expect($range->from)->toBe('2026-01-01')->and($range->to)->toBe('2027-06-30');
});

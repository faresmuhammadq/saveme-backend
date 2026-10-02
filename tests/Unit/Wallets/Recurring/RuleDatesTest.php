<?php

use App\Modules\Wallets\Domain\Recurring\Frequency;
use App\Modules\Wallets\Domain\Recurring\RuleDates;

/*
 * Month-end clamping and no drift (spec 014, FR-010, research R-4).
 */

function datesOf(RuleDates $rule, string $from, string $to, int $cap = 400): array
{
    return array_map(fn (array $round): string => $round['date'], $rule->between($from, $to, $cap));
}

it('clamps a monthly anchor of 31 to each month\'s last day without drifting', function (): void {
    $rule = new RuleDates(Frequency::Monthly, 1, 31, '2027-01-31', null, null);

    expect(datesOf($rule, '2027-01-01', '2027-05-31'))->toBe(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31']);
});

it('puts a leap February on the 29th', function (): void {
    $rule = new RuleDates(Frequency::Monthly, 1, 31, '2028-01-31', null, null);

    expect(datesOf($rule, '2028-02-01', '2028-02-29'))->toBe(['2028-02-29']);
});

it('falls back to 28 February for a yearly anchor of 29 February in a common year', function (): void {
    $rule = new RuleDates(Frequency::Yearly, 1, 29, '2028-02-29', null, null);

    expect(datesOf($rule, '2028-01-01', '2032-12-31'))->toBe(['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29']);
});

it('steps a monthly rule by its interval in months', function (): void {
    $rule = new RuleDates(Frequency::Monthly, 3, 15, '2026-01-15', null, null);

    expect(datesOf($rule, '2026-01-01', '2026-12-31'))->toBe(['2026-01-15', '2026-04-15', '2026-07-15', '2026-10-15']);
});

it('repeats a weekly rule on the start weekday every interval weeks', function (): void {
    $rule = new RuleDates(Frequency::Weekly, 2, null, '2026-10-05', null, null);

    expect(datesOf($rule, '2026-10-01', '2026-11-30'))->toBe(['2026-10-05', '2026-10-19', '2026-11-02', '2026-11-16', '2026-11-30'])
        ->and(date('N', strtotime('2026-11-16')))->toBe(date('N', strtotime('2026-10-05')));
});

it('repeats a daily rule every interval days', function (): void {
    $rule = new RuleDates(Frequency::Daily, 3, null, '2026-10-30', null, null);

    expect(datesOf($rule, '2026-10-01', '2026-11-10'))->toBe(['2026-10-30', '2026-11-02', '2026-11-05', '2026-11-08']);
});

it('starts a monthly rule on the first anchor date on or after the start and numbers it 1', function (): void {
    $rule = new RuleDates(Frequency::Monthly, 1, 5, '2026-10-20', null, null);

    expect($rule->between('2026-01-01', '2027-01-31', 400))->toBe([
        ['ordinal' => 1, 'date' => '2026-11-05'],
        ['ordinal' => 2, 'date' => '2026-12-05'],
        ['ordinal' => 3, 'date' => '2027-01-05'],
    ]);
});

it('takes the start itself when it falls on the anchor', function (): void {
    $rule = new RuleDates(Frequency::Monthly, 1, 20, '2026-10-20', null, null);

    expect($rule->between('2026-01-01', '2026-11-30', 400)[0])->toBe(['ordinal' => 1, 'date' => '2026-10-20']);
});

it('includes the end date and stops after it', function (): void {
    $rule = new RuleDates(Frequency::Monthly, 1, 1, '2026-11-01', '2027-01-01', null);

    expect(datesOf($rule, '2026-01-01', '2030-01-01'))->toBe(['2026-11-01', '2026-12-01', '2027-01-01']);
});

it('stops after the count-th round', function (): void {
    $rule = new RuleDates(Frequency::Monthly, 1, 25, '2026-10-25', null, 3);

    expect(datesOf($rule, '2026-01-01', '2030-01-01'))->toBe(['2026-10-25', '2026-11-25', '2026-12-25']);
});

it('counts rounds from the first even when the walk starts later', function (): void {
    $rule = new RuleDates(Frequency::Monthly, 1, 1, '2026-01-01', null, 10);

    expect($rule->between('2026-04-01', '2026-06-30', 400))->toBe([
        ['ordinal' => 4, 'date' => '2026-04-01'],
        ['ordinal' => 5, 'date' => '2026-05-01'],
        ['ordinal' => 6, 'date' => '2026-06-01'],
    ]);
});

it('computes every date from the origin: no drift over 24 months', function (): void {
    $rule = new RuleDates(Frequency::Monthly, 1, 31, '2026-01-31', null, null);
    $dates = datesOf($rule, '2026-01-01', '2027-12-31');

    expect($dates)->toHaveCount(24)
        ->and($dates[1])->toBe('2026-02-28')
        ->and($dates[2])->toBe('2026-03-31')
        ->and($dates[13])->toBe('2027-02-28')
        ->and($dates[23])->toBe('2027-12-31');
});

it('respects the cap and returns ordinals', function (): void {
    $rule = new RuleDates(Frequency::Daily, 1, null, '2026-01-01', null, null);
    $rounds = $rule->between('2026-01-01', '2026-12-31', 5);

    expect($rounds)->toHaveCount(5)
        ->and($rounds[4])->toBe(['ordinal' => 5, 'date' => '2026-01-05']);
});

it('finds the next round after a date, and none past the end', function (): void {
    $yearly = new RuleDates(Frequency::Yearly, 1, 1, '2026-03-01', null, null);
    $finite = new RuleDates(Frequency::Monthly, 1, 1, '2026-01-01', null, 2);

    expect($yearly->nextAfter('2026-10-02'))->toBe(['ordinal' => 2, 'date' => '2027-03-01'])
        ->and($finite->nextAfter('2026-02-01'))->toBeNull();
});

it('says which round a date is and which are off the schedule', function (): void {
    $rule = new RuleDates(Frequency::Monthly, 1, 31, '2026-10-31', null, 3);

    expect($rule->ordinalOf('2026-11-30'))->toBe(2)
        ->and($rule->ordinalOf('2026-11-29'))->toBeNull()
        ->and($rule->ordinalOf('2026-12-31'))->toBe(3)
        ->and($rule->ordinalOf('2027-01-31'))->toBeNull()
        ->and($rule->isAfterEnd('2027-01-31'))->toBeTrue()
        ->and($rule->isAfterEnd('2026-12-31'))->toBeFalse();
});

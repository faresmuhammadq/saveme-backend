<?php

use App\Modules\Notifications\Domain\Preferences\QuietHours;

function utc(string $at): DateTimeImmutable
{
    return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}

test('quiet hours that wrap midnight contain the evening and the early morning', function () {
    $quiet = new QuietHours(true, 22 * 60, 8 * 60);

    // Cairo is UTC+3 in October 2026 (DST ends on the last Friday of October).
    expect($quiet->contains(utc('2026-10-06 20:30:00'), 'Africa/Cairo'))->toBeTrue()   // 23:30 local
        ->and($quiet->contains(utc('2026-10-06 00:30:00'), 'Africa/Cairo'))->toBeTrue() // 03:30 local
        ->and($quiet->contains(utc('2026-10-06 10:00:00'), 'Africa/Cairo'))->toBeFalse(); // 13:00 local
});

test('quiet hours that do not wrap contain only their window', function () {
    $quiet = new QuietHours(true, 13 * 60, 15 * 60);

    expect($quiet->contains(utc('2026-10-06 11:00:00'), 'Africa/Cairo'))->toBeTrue()   // 14:00 local
        ->and($quiet->contains(utc('2026-10-06 08:00:00'), 'Africa/Cairo'))->toBeFalse() // 11:00 local
        ->and($quiet->contains(utc('2026-10-06 13:00:00'), 'Africa/Cairo'))->toBeFalse(); // 16:00 local
});

test('the start is inclusive and the end exclusive', function () {
    $quiet = new QuietHours(true, 22 * 60, 8 * 60);

    expect($quiet->contains(utc('2026-10-06 19:00:00'), 'Africa/Cairo'))->toBeTrue()   // 22:00 local
        ->and($quiet->contains(utc('2026-10-06 18:59:00'), 'Africa/Cairo'))->toBeFalse()
        ->and($quiet->contains(utc('2026-10-06 05:00:00'), 'Africa/Cairo'))->toBeFalse() // 08:00 local
        ->and($quiet->contains(utc('2026-10-06 04:59:00'), 'Africa/Cairo'))->toBeTrue();
});

test('quiet hours turned off contain nothing', function () {
    expect((new QuietHours(false, 22 * 60, 8 * 60))->contains(utc('2026-10-06 20:30:00'), 'Africa/Cairo'))->toBeFalse();
});

test('the end after a late evening instant is the next morning in the person timezone', function () {
    $quiet = new QuietHours(true, 22 * 60, 8 * 60);

    $end = $quiet->endAfter(utc('2026-10-06 20:30:00'), 'Africa/Cairo'); // 23:30 local

    expect($end->format('Y-m-d H:i'))->toBe('2026-10-07 05:00'); // 08:00 Cairo
});

test('the end after an early morning instant is the same morning', function () {
    $quiet = new QuietHours(true, 22 * 60, 8 * 60);

    expect($quiet->endAfter(utc('2026-10-06 00:30:00'), 'Africa/Cairo')->format('Y-m-d H:i'))->toBe('2026-10-06 05:00');
});

test('the end of a window that does not wrap is later the same day', function () {
    $quiet = new QuietHours(true, 13 * 60, 15 * 60);

    expect($quiet->endAfter(utc('2026-10-06 11:00:00'), 'Africa/Cairo')->format('Y-m-d H:i'))->toBe('2026-10-06 12:00');
});

function localEnd(QuietHours $quiet, string $at, string $zone): string
{
    return $quiet->endAfter(utc($at), $zone)->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i');
}

test('the end is always 08:00 on the wall clock across London daylight-saving changes', function () {
    $quiet = new QuietHours(true, 22 * 60, 8 * 60);

    // Clocks go back on 2026-10-25: the night of the 24th is one hour longer.
    expect(localEnd($quiet, '2026-10-24 22:00:00', 'Europe/London'))->toBe('2026-10-25 08:00')
        ->and($quiet->endAfter(utc('2026-10-24 22:00:00'), 'Europe/London')->format('H:i'))->toBe('08:00')
        ->and(localEnd($quiet, '2026-10-25 23:00:00', 'Europe/London'))->toBe('2026-10-26 08:00')
        // Clocks go forward on 2026-03-29: the night of the 28th is one hour shorter.
        ->and(localEnd($quiet, '2026-03-28 22:30:00', 'Europe/London'))->toBe('2026-03-29 08:00')
        ->and($quiet->endAfter(utc('2026-03-28 22:30:00'), 'Europe/London')->format('H:i'))->toBe('07:00');
});

test('the end is always 08:00 on the wall clock across Cairo daylight-saving changes', function () {
    $quiet = new QuietHours(true, 22 * 60, 8 * 60);

    // Egypt falls back at the end of October and springs forward at the end of April.
    expect(localEnd($quiet, '2026-10-28 22:00:00', 'Africa/Cairo'))->toBe('2026-10-29 08:00')
        ->and(localEnd($quiet, '2026-10-30 22:30:00', 'Africa/Cairo'))->toBe('2026-10-31 08:00')
        ->and(localEnd($quiet, '2026-04-23 21:00:00', 'Africa/Cairo'))->toBe('2026-04-24 08:00')
        ->and(localEnd($quiet, '2026-04-24 21:00:00', 'Africa/Cairo'))->toBe('2026-04-25 08:00');
});

test('a local end time that does not exist moves forward to the first valid instant', function () {
    // London springs forward on 2026-03-29: 01:00 to 02:00. An end of 01:30 does not exist that day.
    $quiet = new QuietHours(true, 22 * 60, 90);

    expect($quiet->endAfter(utc('2026-03-28 23:30:00'), 'Europe/London')->format('Y-m-d H:i'))->toBe('2026-03-29 01:30');
});

test('times parse only on a quarter hour', function () {
    expect(QuietHours::parse('22:00'))->toBe(1320)
        ->and(QuietHours::parse('23:45'))->toBe(1425)
        ->and(QuietHours::parse('23:10'))->toBeNull()
        ->and(QuietHours::parse('24:00'))->toBeNull()
        ->and(QuietHours::parse('7:00'))->toBeNull()
        ->and(QuietHours::formatMinutes(480))->toBe('08:00');
});

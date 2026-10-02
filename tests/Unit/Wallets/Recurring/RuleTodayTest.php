<?php

use App\Modules\Wallets\Domain\Recurring\RuleToday;

/*
 * "Today" is the rule's timezone, not the viewer's or the server's (P-12,
 * ADR-0011 rule 9, spec 014).
 */

it('gives different calendar days for different timezones at 21:30 UTC', function (): void {
    $now = new DateTimeImmutable('2026-11-30 21:30:00', new DateTimeZone('UTC'));

    expect(RuleToday::of('UTC', $now))->toBe('2026-11-30')
        ->and(RuleToday::of('Africa/Cairo', $now))->toBe('2026-11-30')
        ->and(RuleToday::of('Asia/Dubai', $now))->toBe('2026-12-01');
});

it('changes the day at the rule\'s own midnight', function (): void {
    $before = new DateTimeImmutable('2026-11-30 21:59:59', new DateTimeZone('UTC'));
    $after = new DateTimeImmutable('2026-11-30 22:00:00', new DateTimeZone('UTC'));

    expect(RuleToday::of('Africa/Cairo', $before))->toBe('2026-11-30')
        ->and(RuleToday::of('Africa/Cairo', $after))->toBe('2026-12-01');
});

it('resolves one calendar date across a daylight saving switch', function (): void {
    $dates = [];
    foreach (['2027-03-28 00:30:00', '2027-03-28 01:30:00', '2027-03-28 21:30:00'] as $instant) {
        $dates[] = RuleToday::of('Europe/Berlin', new DateTimeImmutable($instant, new DateTimeZone('UTC')));
    }

    expect(array_unique($dates))->toBe(['2027-03-28']);
});

it('is driven by the instant it is given', function (): void {
    expect(RuleToday::of('Africa/Cairo', new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'))))->toBe('2026-01-01');
});

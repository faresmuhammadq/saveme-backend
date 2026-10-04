<?php

use App\Modules\Billing\Domain\RedeemCodes\RedeemedPeriod;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;

/*
 * The billing period a redemption creates (spec 020, FR-029, R-8; P-12).
 */

function at(string $instant): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
}

function held(string $provider, ?string $end, string $status = 'active', ?string $graceUntil = null): SubscriptionRecord
{
    return new SubscriptionRecord(
        id: 'sub-'.$provider.'-'.($end ?? 'life'),
        userId: 'user-1',
        planId: 'plan-1',
        provider: $provider,
        providerRef: 'ref-'.$provider.'-'.($end ?? 'life'),
        status: SubscriptionStatus::from($status),
        currentPeriodStart: at('2026-01-01 00:00:00'),
        currentPeriodEnd: $end === null ? null : at($end),
        graceUntil: $graceUntil === null ? null : at($graceUntil),
        autoRenew: false,
        createdAt: at('2026-01-01 00:00:00'),
    );
}

function period(string $type, ?int $days, string $now, array $held = []): array
{
    $period = RedeemedPeriod::for($type, $days, at($now), $held);

    return [$period->start->format('Y-m-d H:i:s'), $period->end?->format('Y-m-d H:i:s')];
}

test('monthly adds one calendar month without overflow', function (string $now, string $end) {
    expect(period('monthly', null, $now))->toBe([$now, $end]);
})->with([
    'an ordinary day' => ['2026-10-05 12:00:00', '2026-11-05 12:00:00'],
    'January 31st' => ['2027-01-31 08:30:00', '2027-02-28 08:30:00'],
    'January 31st in a leap year' => ['2028-01-31 08:30:00', '2028-02-29 08:30:00'],
    'October 31st' => ['2026-10-31 23:59:59', '2026-11-30 23:59:59'],
    'December' => ['2026-12-15 00:00:00', '2027-01-15 00:00:00'],
]);

test('yearly adds one calendar year without overflow', function (string $now, string $end) {
    expect(period('yearly', null, $now))->toBe([$now, $end]);
})->with([
    'an ordinary day' => ['2026-10-05 12:00:00', '2027-10-05 12:00:00'],
    'February 29th' => ['2028-02-29 06:00:00', '2029-02-28 06:00:00'],
    'a leap year to a leap year' => ['2027-02-28 06:00:00', '2028-02-28 06:00:00'],
]);

test('custom lasts its days and lifetime has no end', function () {
    expect(period('custom', 45, '2026-10-05 12:00:00'))->toBe(['2026-10-05 12:00:00', '2026-11-19 12:00:00'])
        ->and(period('lifetime', null, '2026-10-05 12:00:00'))->toBe(['2026-10-05 12:00:00', null]);
});

test('an instant is read in UTC whatever zone it arrives in', function () {
    $cairo = new DateTimeImmutable('2026-10-05 03:00:00', new DateTimeZone('Africa/Cairo'));

    $period = RedeemedPeriod::for('monthly', null, $cairo, []);

    expect($period->start->getTimezone()->getName())->toBe('UTC')
        ->and($period->start->format('Y-m-d H:i:s'))->toBe('2026-10-05 00:00:00')
        ->and($period->end->format('Y-m-d H:i:s'))->toBe('2026-11-05 00:00:00');
});

test('an early renewal starts where the latest granting InstaPay period ends', function () {
    $held = [held('instapay', '2026-10-10 12:00:00'), held('instapay', '2026-10-08 12:00:00')];

    expect(period('monthly', null, '2026-10-05 12:00:00', $held))->toBe(['2026-10-10 12:00:00', '2026-11-10 12:00:00']);
});

test('an InstaPay period that already ended does not chain', function () {
    expect(period('monthly', null, '2026-10-05 12:00:00', [held('instapay', '2026-10-01 12:00:00', 'expired')]))
        ->toBe(['2026-10-05 12:00:00', '2026-11-05 12:00:00']);
});

test('other channels never chain: Play, manual', function (string $provider) {
    expect(period('monthly', null, '2026-10-05 12:00:00', [held($provider, '2026-10-10 12:00:00')]))
        ->toBe(['2026-10-05 12:00:00', '2026-11-05 12:00:00']);
})->with(['google_play', 'manual']);

test('a lifetime InstaPay subscription has no end to chain from', function () {
    expect(period('yearly', null, '2026-10-05 12:00:00', [held('instapay', null)]))->toBe(['2026-10-05 12:00:00', '2027-10-05 12:00:00']);
});

test('a revoked InstaPay subscription does not chain, and one in grace chains only from a later end', function () {
    expect(period('monthly', null, '2026-10-05 12:00:00', [held('instapay', '2026-10-10 12:00:00', 'revoked')]))
        ->toBe(['2026-10-05 12:00:00', '2026-11-05 12:00:00'])
        ->and(period('monthly', null, '2026-10-05 12:00:00', [held('instapay', '2026-10-04 12:00:00', 'grace', '2026-10-07 12:00:00')]))
        ->toBe(['2026-10-05 12:00:00', '2026-11-05 12:00:00']);
});

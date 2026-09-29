<?php

use App\Modules\Billing\Domain\Subscriptions\GoverningSubscription;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;

/*
 * ADR-0013 rule 14: the latest-ending granting subscription governs.
 */

function sub(string $id, string $status = 'active', ?string $end = '+10 days', ?string $grace = null, string $created = '-10 days'): SubscriptionRecord
{
    $now = new DateTimeImmutable('2026-09-28 12:00:00');

    return new SubscriptionRecord(
        $id, 'user', 'plan', 'manual', "ref-{$id}", SubscriptionStatus::from($status),
        $now->modify('-20 days'), $end === null ? null : $now->modify($end), $grace === null ? null : $now->modify($grace),
        false, $now->modify($created),
    );
}

$now = new DateTimeImmutable('2026-09-28 12:00:00');

test('no subscriptions means none governs', function () use ($now) {
    expect(GoverningSubscription::among([], $now))->toBeNull();
});

test('expired, revoked and date-lapsed subscriptions never govern', function () use ($now) {
    expect(GoverningSubscription::among([
        sub('a', 'expired'), sub('b', 'revoked'),
        sub('c', 'active', end: '-1 hour'),
        sub('d', 'grace', end: '-5 days', grace: '-1 hour'),
    ], $now))->toBeNull();
});

test('a grace subscription still inside its grace governs', function () use ($now) {
    expect(GoverningSubscription::among([sub('a', 'grace', end: '-1 day', grace: '+2 days')], $now)?->id)->toBe('a');
});

test('the latest end wins, and lifetime beats any end', function () use ($now) {
    expect(GoverningSubscription::among([sub('a', end: '+10 days'), sub('b', end: '+40 days')], $now)?->id)->toBe('b')
        ->and(GoverningSubscription::among([sub('a', end: '+400 days'), sub('b', end: null)], $now)?->id)->toBe('b');
});

test('ties go to the later created, then the higher id', function () use ($now) {
    expect(GoverningSubscription::among([sub('a', created: '-1 day'), sub('b', created: '-5 days')], $now)?->id)->toBe('a')
        ->and(GoverningSubscription::among([sub('a'), sub('b')], $now)?->id)->toBe('b');
});

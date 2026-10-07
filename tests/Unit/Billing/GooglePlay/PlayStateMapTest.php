<?php

use App\Modules\Billing\Domain\GooglePlay\PlayProductKind;
use App\Modules\Billing\Domain\GooglePlay\PlayPurchaseRecord;
use App\Modules\Billing\Domain\GooglePlay\PlayState;
use App\Modules\Billing\Domain\GooglePlay\PlayStateMap;
use App\Modules\Billing\Domain\GooglePlay\PlayTransition;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionRecord;
use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus;

/*
 * Google's states onto ADR-0013 rule 4 (spec 024, R-5, FR-020 to FR-029): no
 * undrawn arc, the end never moves backwards, revoked is terminal, a hold
 * from active is the zero-length grace hop with one lapse.
 */

const PLAY_NOW = '2026-10-06 12:00:00';

function playNow(): DateTimeImmutable
{
    return new DateTimeImmutable(PLAY_NOW);
}

function googleRecord(PlayState $state, string $end = '+30 days', array $overrides = []): PlayPurchaseRecord
{
    $now = playNow();
    $values = [
        'productId' => 'plus_monthly', 'kind' => PlayProductKind::Subscription, 'state' => $state,
        'expiresAt' => $end === '' ? null : $now->modify($end),
        'graceUntil' => $state === PlayState::Grace ? $now->modify($end) : null,
        'purchasedAt' => $now->modify('-1 day'), 'autoRenew' => true, 'acknowledged' => false, 'obfuscatedRef' => null,
        'linkedToken' => null, 'isTest' => false, 'basePlanId' => 'monthly', ...$overrides,
    ];

    return new PlayPurchaseRecord(...$values);
}

function localSubscription(SubscriptionStatus $status, ?string $end = '+10 days', ?string $grace = null, bool $autoRenew = true): SubscriptionRecord
{
    $now = playNow();

    return new SubscriptionRecord('sub-1', 'user-1', 'plan-1', 'google_play', 'purchase-1', $status, $now->modify('-20 days'), $end === null ? null : $now->modify($end), $grace === null ? null : $now->modify($grace), $autoRenew, $now->modify('-20 days'));
}

test('a paid purchase with no subscription creates an active one and publishes an activation', function () {
    $t = PlayStateMap::target(googleRecord(PlayState::Active), null, playNow());

    expect($t->create)->toBeTrue()
        ->and($t->status)->toBe(SubscriptionStatus::Active)
        ->and($t->auditAction)->toBe('purchase')
        ->and($t->event)->toBe(PlayTransition::EVENT_ACTIVATED)
        ->and($t->periodEnd)->toEqual(playNow()->modify('+30 days'))
        ->and($t->graceUntil)->toBeNull();
});

test('a renewal moves the end forward, starts the new period at the old end, audits and publishes nothing', function () {
    $current = localSubscription(SubscriptionStatus::Active, '+1 day');
    $t = PlayStateMap::target(googleRecord(PlayState::Active, '+31 days'), $current, playNow());

    expect($t->create)->toBeFalse()
        ->and($t->status)->toBe(SubscriptionStatus::Active)
        ->and($t->auditAction)->toBe('renew')
        ->and($t->event)->toBeNull()
        ->and($t->periodStart)->toEqual($current->currentPeriodEnd)
        ->and($t->periodEnd)->toEqual(playNow()->modify('+31 days'));
});

test('the end never moves backwards', function () {
    $current = localSubscription(SubscriptionStatus::Active, '+20 days');
    $t = PlayStateMap::target(googleRecord(PlayState::Active, '+5 days'), $current, playNow());

    expect($t->changed)->toBeFalse();
});

test('a repeat of the same state changes nothing', function () {
    $current = localSubscription(SubscriptionStatus::Active, '+30 days');

    expect(PlayStateMap::target(googleRecord(PlayState::Active, '+30 days'), $current, playNow())->changed)->toBeFalse();
});

test('a change of auto-renew alone is written without an audit entry or an event', function () {
    $current = localSubscription(SubscriptionStatus::Active, '+30 days');
    $t = PlayStateMap::target(googleRecord(PlayState::Canceled, '+30 days'), $current, playNow());

    expect($t->changed)->toBeTrue()->and($t->autoRenew)->toBeFalse()->and($t->auditAction)->toBeNull()->and($t->event)->toBeNull();
});

test('grace from active moves to grace with Google\'s grace end and one lapse', function () {
    $t = PlayStateMap::target(googleRecord(PlayState::Grace, '+3 days'), localSubscription(SubscriptionStatus::Active, '-1 day'), playNow());

    expect($t->status)->toBe(SubscriptionStatus::Grace)
        ->and($t->graceUntil)->toEqual(playNow()->modify('+3 days'))
        ->and($t->event)->toBe(PlayTransition::EVENT_LAPSED)
        ->and($t->viaGraceHop)->toBeFalse();
});

test('grace again with the same end changes nothing, a longer grace extends it', function () {
    $current = localSubscription(SubscriptionStatus::Grace, '-1 day', '+3 days');

    expect(PlayStateMap::target(googleRecord(PlayState::Grace, '+3 days'), $current, playNow())->changed)->toBeFalse();
    expect(PlayStateMap::target(googleRecord(PlayState::Grace, '+5 days'), $current, playNow())->graceUntil)->toEqual(playNow()->modify('+5 days'));
});

test('hold and pause from active are the zero-length grace hop with exactly one lapse', function (PlayState $state) {
    $t = PlayStateMap::target(googleRecord($state, '-1 day'), localSubscription(SubscriptionStatus::Active, '-1 day'), playNow());

    expect($t->status)->toBe(SubscriptionStatus::Expired)
        ->and($t->viaGraceHop)->toBeTrue()
        ->and($t->graceUntil)->toEqual(playNow())
        ->and($t->event)->toBe(PlayTransition::EVENT_LAPSED)
        ->and($t->auditAction)->toBe('expire');
})->with([PlayState::OnHold, PlayState::Paused, PlayState::Expired]);

test('hold from grace expires directly, no hop', function () {
    $t = PlayStateMap::target(googleRecord(PlayState::OnHold, '-1 day'), localSubscription(SubscriptionStatus::Grace, '-2 days', '+1 day'), playNow());

    expect($t->status)->toBe(SubscriptionStatus::Expired)->and($t->viaGraceHop)->toBeFalse()->and($t->event)->toBe(PlayTransition::EVENT_LAPSED);
});

test('an expired subscription stays expired, and a hold with no subscription creates none', function () {
    expect(PlayStateMap::target(googleRecord(PlayState::OnHold), localSubscription(SubscriptionStatus::Expired, '-5 days'), playNow())->changed)->toBeFalse();
    expect(PlayStateMap::target(googleRecord(PlayState::OnHold), null, playNow())->changed)->toBeFalse();
});

test('recovery from hold, resume from pause and a restart move expired or grace back to active', function (SubscriptionStatus $from) {
    $current = localSubscription($from, '-5 days', $from === SubscriptionStatus::Grace ? '+1 day' : '-2 days');
    $t = PlayStateMap::target(googleRecord(PlayState::Active, '+30 days'), $current, playNow());

    expect($t->status)->toBe(SubscriptionStatus::Active)
        ->and($t->auditAction)->toBe('recover')
        ->and($t->event)->toBe(PlayTransition::EVENT_ACTIVATED)
        ->and($t->graceUntil)->toBeNull()
        ->and($t->periodEnd)->toEqual(playNow()->modify('+30 days'));
})->with([SubscriptionStatus::Expired, SubscriptionStatus::Grace]);

test('canceled keeps access with auto-renew off until the end, then expires', function () {
    $granting = PlayStateMap::target(googleRecord(PlayState::Canceled, '+10 days'), localSubscription(SubscriptionStatus::Active, '+10 days'), playNow());
    $ended = PlayStateMap::target(googleRecord(PlayState::Canceled, '-1 hour'), localSubscription(SubscriptionStatus::Active, '-1 hour'), playNow());

    expect($granting->status)->toBe(SubscriptionStatus::Active)->and($granting->autoRenew)->toBeFalse()
        ->and($ended->status)->toBe(SubscriptionStatus::Expired)->and($ended->viaGraceHop)->toBeTrue();
});

test('a revocation revokes a granting subscription once and publishes one lapse', function (SubscriptionStatus $from) {
    $t = PlayStateMap::target(googleRecord(PlayState::Revoked), localSubscription($from, '+10 days'), playNow(), 'refunded');

    expect($t->status)->toBe(SubscriptionStatus::Revoked)
        ->and($t->auditAction)->toBe('revoke')->and($t->auditReason)->toBe('refunded')
        ->and($t->event)->toBe(PlayTransition::EVENT_LAPSED);
})->with([SubscriptionStatus::Active, SubscriptionStatus::Grace]);

test('revoked is terminal: a later active changes nothing and is flagged ignored', function () {
    $t = PlayStateMap::target(googleRecord(PlayState::Active), localSubscription(SubscriptionStatus::Revoked), playNow());

    expect($t->changed)->toBeFalse()->and($t->ignored)->toBeTrue();
});

test('an expired subscription is not revoked: rule 4 draws no such arc', function () {
    expect(PlayStateMap::target(googleRecord(PlayState::Revoked), localSubscription(SubscriptionStatus::Expired, '-3 days'), playNow())->changed)->toBeFalse();
});

test('pending and pending-canceled grant nothing', function (PlayState $state) {
    expect(PlayStateMap::target(googleRecord($state), null, playNow())->changed)->toBeFalse();
})->with([PlayState::Pending, PlayState::PendingCanceled]);

test('a one-time purchase grants for life, a canceled one is never granted, a refunded one is revoked', function () {
    $lifetime = fn (PlayState $state) => googleRecord($state, '', ['kind' => PlayProductKind::OneTime, 'autoRenew' => false]);

    $created = PlayStateMap::target($lifetime(PlayState::Active), null, playNow());
    expect($created->create)->toBeTrue()->and($created->periodEnd)->toBeNull();

    expect(PlayStateMap::target($lifetime(PlayState::PendingCanceled), null, playNow())->changed)->toBeFalse();
    expect(PlayStateMap::target($lifetime(PlayState::PendingCanceled), localSubscription(SubscriptionStatus::Active, null), playNow())->status)->toBe(SubscriptionStatus::Revoked);
    expect(PlayStateMap::target($lifetime(PlayState::Revoked), localSubscription(SubscriptionStatus::Active, null), playNow())->status)->toBe(SubscriptionStatus::Revoked);
    expect(PlayStateMap::target($lifetime(PlayState::Active), localSubscription(SubscriptionStatus::Active, null, autoRenew: false), playNow())->changed)->toBeFalse();
});

test('every transition it produces is a drawn arc or the hop', function () {
    $now = playNow();

    foreach (PlayState::cases() as $state) {
        foreach (SubscriptionStatus::cases() as $from) {
            $t = PlayStateMap::target(googleRecord($state, '+3 days'), localSubscription($from, '-1 day', '+1 day'), $now);

            if (! $t->changed || $t->create || $t->status === $from) {
                continue;
            }

            $legal = $t->viaGraceHop
                ? $from === SubscriptionStatus::Active && $t->status === SubscriptionStatus::Expired
                : $from->canBecome($t->status);

            expect($legal)->toBeTrue("{$state->value} from {$from->value} to {$t->status->value}");
        }
    }
});

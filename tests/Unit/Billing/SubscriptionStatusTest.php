<?php

use App\Modules\Billing\Domain\Subscriptions\SubscriptionStatus as S;

test('only the ADR machine\'s transitions are allowed', function () {
    $allowed = ['active>grace', 'grace>expired', 'active>revoked', 'grace>revoked', 'expired>active', 'grace>active'];

    foreach (S::cases() as $from) {
        foreach (S::cases() as $to) {
            expect($from->canBecome($to))->toBe(in_array("{$from->value}>{$to->value}", $allowed, true), "{$from->value}>{$to->value}");
        }
    }
});

test('only active and grace grant', function () {
    expect(S::Active->grants())->toBeTrue()
        ->and(S::Grace->grants())->toBeTrue()
        ->and(S::Expired->grants())->toBeFalse()
        ->and(S::Revoked->grants())->toBeFalse();
});

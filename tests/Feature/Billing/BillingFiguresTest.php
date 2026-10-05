<?php

use App\Modules\Billing\Application\Figures\BillingFigures;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\User;

/*
 * People with access on a non-default plan, by channel, and subscriptions in
 * grace (spec 021, FR-010; R-11).
 */

beforeEach(function () {
    $this->freezeTime();
});

function figuresFor(): object
{
    return app(BillingFigures::class)->compute(now()->toDateTimeImmutable());
}

test('people are counted once per channel and a person on two channels is counted in each', function () {
    $both = User::factory()->create()->id;
    Subscription::factory()->provider('google_play')->create(['user_id' => $both]);
    Subscription::factory()->provider('instapay')->create(['user_id' => $both]);
    Subscription::factory()->provider('google_play')->create(['user_id' => $both]);
    Subscription::factory()->provider('instapay')->create(['user_id' => User::factory()->create()->id]);
    Subscription::factory()->provider('manual')->lifetime()->create(['user_id' => User::factory()->create()->id]);

    $figures = figuresFor();

    expect($figures->paidGooglePlay)->toBe(1)
        ->and($figures->paidInstapay)->toBe(2)
        ->and($figures->grantedManual)->toBe(1);
});

test('only granting subscriptions on a non-default plan count', function () {
    Subscription::factory()->provider('instapay')->expired()->create(['user_id' => User::factory()->create()->id]);
    Subscription::factory()->provider('instapay')->revoked()->create(['user_id' => User::factory()->create()->id]);
    Subscription::factory()->provider('instapay')->forPlan('free')->create(['user_id' => User::factory()->create()->id]);
    Subscription::factory()->provider('instapay')->grace()->create(['user_id' => User::factory()->create()->id]);

    $figures = figuresFor();

    expect($figures->paidInstapay)->toBe(1)
        ->and($figures->inGrace)->toBe(1);
});

test('nothing granted is all zeros', function () {
    $figures = figuresFor();

    expect([$figures->paidGooglePlay, $figures->paidInstapay, $figures->grantedManual, $figures->inGrace])->toBe([0, 0, 0, 0]);
});

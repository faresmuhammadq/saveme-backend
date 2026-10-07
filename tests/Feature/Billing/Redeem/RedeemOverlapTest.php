<?php

use App\Modules\Billing\Application\RedeemCodes\RedeemCodes;
use App\Modules\Billing\Application\Subscriptions\LapseSubscriptions;
use App\Modules\Billing\Application\Subscriptions\MySubscription;
use App\Modules\Billing\Domain\RedeemCodes\RedeemCode;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode as RedeemCodeRow;
use App\Modules\Billing\Infrastructure\Models\Subscription;

/*
 * Redemption beside other channels, and early renewal (spec 020, US4,
 * FR-029, FR-030; ADR-0013 rule 14).
 */

beforeEach(function () {
    $this->freezeTime();
    $this->user = person('ada@example.test');
});

function redeemPlan(string $plan, string $userId): array
{
    $typed = RedeemCode::generate()->display();
    $claim = PaymentClaim::factory()->forAddress('ada@example.test')->forPlan($plan)->approved()->create();
    RedeemCodeRow::factory()->forCode($typed)->state(['claim_id' => $claim->id])->create();
    app()->forgetScopedInstances();

    return app(RedeemCodes::class)->redeem($userId, $typed, 'en')->toWire();
}

function instapayRow(): Subscription
{
    return Subscription::query()->where('provider', 'instapay')->orderByDesc('created_at')->firstOrFail();
}

test('a Play subscription ending sooner is untouched and the redeemed plan governs', function () {
    $play = Subscription::factory()->provider('google_play')->forPlan('plus_monthly')->endingIn(20)->create(['user_id' => $this->user->id]);
    $before = $play->fresh()->getAttributes();

    $wire = redeemPlan('plus_yearly', $this->user->id);

    expect($play->fresh()->getAttributes())->toBe($before)
        ->and($wire['governing']['provider'])->toBe('instapay')
        ->and($wire['governing']['plan']['name'])->toBe('Plus · Yearly')
        ->and($wire['entitlements']['plan']['name'])->toBe('Plus · Yearly');
});

test('a Play subscription ending later keeps governing and the answer says which plan that is', function () {
    Subscription::factory()->provider('google_play')->forPlan('plus_yearly')->endingIn(300)->create(['user_id' => $this->user->id]);

    $wire = redeemPlan('plus_monthly', $this->user->id);

    expect($wire['subscription']['provider'])->toBe('instapay')
        ->and($wire['subscription']['plan']['name'])->toBe('Plus · Monthly')
        ->and($wire['governing']['provider'])->toBe('google_play')
        ->and($wire['governing']['plan']['name'])->toBe('Plus · Yearly')
        ->and($wire['entitlements']['plan']['name'])->toBe('Plus · Yearly');
});

test('a Play lifetime subscription keeps governing', function () {
    $play = Subscription::factory()->provider('google_play')->forPlan('plus_yearly')->lifetime()->create(['user_id' => $this->user->id]);

    $wire = redeemPlan('plus_yearly', $this->user->id);

    expect(Subscription::query()->count())->toBe(2)
        ->and($wire['governing']['provider'])->toBe('google_play')
        ->and($wire['governing']['current_period_end'])->toBeNull()
        ->and($play->fresh()->status)->toBe('active');
});

test('a granting InstaPay subscription ending in 5 days: the new period starts at that end', function () {
    $held = Subscription::factory()->provider('instapay')->forPlan('plus_monthly')->endingIn(5)->create(['user_id' => $this->user->id]);

    $wire = redeemPlan('plus_yearly', $this->user->id);

    $created = Subscription::query()->where('id', $wire['subscription']['id'])->sole();
    expect($created->current_period_start->equalTo($held->current_period_end))->toBeTrue()
        ->and($created->current_period_end->equalTo($held->current_period_end->addYear()))->toBeTrue()
        ->and($wire['governing']['plan']['name'])->toBe('Plus · Yearly');
});

test('renewing twice early chains again from the latest end', function () {
    $first = redeemPlan('plus_monthly', $this->user->id);
    $second = redeemPlan('plus_monthly', $this->user->id);

    expect($second['subscription']['current_period_start'])->toBe($first['subscription']['current_period_end'])
        ->and(Subscription::query()->count())->toBe(2);
});

test('a manual or Play subscription ending in 5 days does not chain: the new period starts now', function (string $provider) {
    Subscription::factory()->provider($provider)->forPlan('plus_monthly')->endingIn(5)->create(['user_id' => $this->user->id]);

    $wire = redeemPlan('plus_yearly', $this->user->id);

    expect(Subscription::query()->where('id', $wire['subscription']['id'])->sole()->current_period_start->equalTo(now()))->toBeTrue();
})->with(['manual', 'google_play']);

test('an InstaPay subscription in grace has ended, so the new period starts now', function () {
    Subscription::factory()->provider('instapay')->forPlan('plus_monthly')->grace(2)->create(['user_id' => $this->user->id]);

    $wire = redeemPlan('plus_yearly', $this->user->id);

    expect(Subscription::query()->where('id', $wire['subscription']['id'])->sole()->current_period_start->equalTo(now()))->toBeTrue();
});

test('after the lapse job runs, entitlements still follow the governing rule', function () {
    // Not google_play: the date-driven lapse leaves it to Google (spec 024, FR-046).
    $manual = Subscription::factory()->provider('manual')->forPlan('plus_monthly')->endingIn(2)->create(['user_id' => $this->user->id]);
    redeemPlan('plus_yearly', $this->user->id);

    $this->travel(10)->days();
    app(LapseSubscriptions::class)->run();
    app()->forgetScopedInstances();

    $wire = app(MySubscription::class)->toWire($this->user->id, 'en');

    expect($manual->fresh()->status)->toBe('expired')
        ->and($wire['provider'])->toBe('instapay')
        ->and($wire['plan']['name'])->toBe('Plus · Yearly');
});

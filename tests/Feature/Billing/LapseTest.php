<?php

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Events\SubscriptionLapsed;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\AuditEntry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Subscriptions lapse on their dates (spec 009, US5; ADR-0013 rule 4).
 */

beforeEach(function () {
    $this->freezeTime();
    $this->user = (string) Str::uuid7();
});

function lapse(): void
{
    test()->artisan('billing:lapse-subscriptions')->assertSuccessful();
    app()->forgetScopedInstances();
}

test('an active subscription past its period moves into three days of grace, still granting', function () {
    $sub = Subscription::factory()->create(['user_id' => $this->user, 'current_period_end' => now()->subHour()]);

    lapse();

    $fresh = $sub->fresh();
    expect($fresh->status)->toBe('grace')
        ->and($fresh->grace_until->equalTo(now()->subHour()->addDays(3)))->toBeTrue()
        ->and(app(EntitlementService::class)->forUser($this->user)->allows(Feature::SharingEnabled))->toBeTrue();
});

test('a grace subscription past its grace expires and grants nothing', function () {
    $sub = Subscription::factory()->create(['user_id' => $this->user, 'status' => 'grace', 'current_period_end' => now()->subDays(4), 'grace_until' => now()->subHour()]);

    lapse();

    expect($sub->fresh()->status)->toBe('expired')
        ->and(app(EntitlementService::class)->forUser($this->user)->allows(Feature::SharingEnabled))->toBeFalse();
});

test('a lifetime subscription never lapses', function () {
    $sub = Subscription::factory()->lifetime()->create(['user_id' => $this->user]);
    $this->travel(5)->years();

    lapse();

    expect($sub->fresh()->status)->toBe('active');
});

test('running twice moves nothing twice, and each move is announced once', function () {
    Event::fake([SubscriptionLapsed::class]);
    Subscription::factory()->create(['user_id' => $this->user, 'current_period_end' => now()->subHour()]);

    lapse();
    lapse();

    Event::assertDispatchedTimes(SubscriptionLapsed::class, 1);
    Event::assertDispatched(SubscriptionLapsed::class, fn (SubscriptionLapsed $e): bool => $e->status === 'grace');
});

test('expiry is audited only when the person\'s plan changes', function () {
    $alone = Subscription::factory()->create(['user_id' => $this->user, 'status' => 'grace', 'current_period_end' => now()->subDays(4), 'grace_until' => now()->subHour()]);
    $covered = (string) Str::uuid7();
    Subscription::factory()->create(['user_id' => $covered, 'status' => 'grace', 'current_period_end' => now()->subDays(4), 'grace_until' => now()->subHour()]);
    Subscription::factory()->forPlan('plus_yearly')->create(['user_id' => $covered]);

    lapse();

    $entries = AuditEntry::query()->where('event', 'entitlement_changed')->get();
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->subject_id)->toBe($alone->id)
        ->and($entries->first()->actor_type)->toBe('system')
        ->and($entries->first()->detail['action'])->toBe('expire');
});

test('a google_play subscription is never moved by the date-driven lapse: Google is the source of truth (spec 024, FR-046)', function () {
    $active = Subscription::factory()->provider('google_play')->create(['user_id' => $this->user, 'current_period_end' => now()->subHour()]);
    $grace = Subscription::factory()->provider('google_play')->create(['user_id' => $this->user, 'status' => 'grace', 'current_period_end' => now()->subDays(4), 'grace_until' => now()->subHour()]);
    Event::fake([SubscriptionLapsed::class]);

    lapse();

    expect($active->fresh()->status)->toBe('active')
        ->and($grace->fresh()->status)->toBe('grace');
    Event::assertNotDispatched(SubscriptionLapsed::class);
});

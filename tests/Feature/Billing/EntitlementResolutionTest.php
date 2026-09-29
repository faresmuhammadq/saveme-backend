<?php

use App\Modules\Billing\Application\Entitlements\Entitlements;
use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Resolving what a person may do (spec 009, US1; ADR-0013 rules 8, 13, 14).
 */

function entitlementsFor(string $userId): Entitlements
{
    app()->forgetScopedInstances();

    return app(EntitlementService::class)->forUser($userId);
}

test('a person with no subscription has the free plan', function () {
    $user = person();

    $entitlements = entitlementsFor($user->id);

    expect($entitlements->allows(Feature::SharingEnabled))->toBeFalse()
        ->and($entitlements->quota(Feature::SharingMaxMembersPerWallet))->toBe(1)
        ->and($entitlements->allows(Feature::ExportEnabled))->toBeTrue()
        ->and($entitlements->quota(Feature::WalletsMax))->toBeNull()
        ->and($entitlements->summary()->planName['en'])->toBe('Free')
        ->and(DB::table('subscriptions')->count())->toBe(0);
});

test('an active or grace Plus subscription grants sharing with five members', function (string $state) {
    $userId = (string) Str::uuid7();
    ($state === 'grace' ? Subscription::factory()->grace() : Subscription::factory())->create(['user_id' => $userId]);

    $entitlements = entitlementsFor($userId);

    expect($entitlements->allows(Feature::SharingEnabled))->toBeTrue()
        ->and($entitlements->quota(Feature::SharingMaxMembersPerWallet))->toBe(5);
})->with(['active', 'grace']);

test('a subscription past its grace grants nothing, even before the lapse runs', function () {
    $userId = (string) Str::uuid7();
    Subscription::factory()->create(['user_id' => $userId, 'status' => 'grace', 'current_period_end' => now()->subDays(5), 'grace_until' => now()->subHour()]);

    expect(entitlementsFor($userId)->allows(Feature::SharingEnabled))->toBeFalse();
});

test('expired and revoked subscriptions grant nothing', function (string $state) {
    $userId = (string) Str::uuid7();
    Subscription::factory()->{$state}()->create(['user_id' => $userId]);

    expect(entitlementsFor($userId)->allows(Feature::SharingEnabled))->toBeFalse();
})->with(['expired', 'revoked']);

test('with overlapping subscriptions the latest-ending one governs, and lifetime beats all', function () {
    $userId = (string) Str::uuid7();
    Subscription::factory()->provider('instapay')->endingIn(10)->create(['user_id' => $userId]);
    $manual = Subscription::factory()->forPlan('plus_yearly')->endingIn(40)->create(['user_id' => $userId]);

    expect(entitlementsFor($userId)->summary()->planId)->toBe($manual->plan_id);

    $lifetime = Subscription::factory()->lifetime()->create(['user_id' => $userId]);
    expect(entitlementsFor($userId)->summary()->planId)->toBe($lifetime->plan_id);
});

test('answers are memoised within a scope and fresh in the next', function () {
    $userId = (string) Str::uuid7();
    app()->forgetScopedInstances();
    $service = app(EntitlementService::class);
    $service->forUser($userId);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $service->forUser($userId);
    expect($queries)->toBe(0);

    Subscription::factory()->create(['user_id' => $userId]);
    expect($service->forUser($userId)->allows(Feature::SharingEnabled))->toBeFalse()
        ->and(entitlementsFor($userId)->allows(Feature::SharingEnabled))->toBeTrue();
});

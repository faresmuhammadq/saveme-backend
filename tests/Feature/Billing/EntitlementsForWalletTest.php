<?php

use App\Modules\Billing\Application\Entitlements\EntitlementService;
use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Wallets\Application\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * A shared wallet is governed by its owner's entitlements (spec 010, US5;
 * P-09 rule 4, ADR-0013 rule 7).
 */

test('forWallet equals forUser of the owner, whoever asks', function () {
    $owner = person();
    $member = person('grace@example.test');
    $wallet = ownedWallet($owner->id);
    addMember($wallet->id, $member->id, 'editor');
    Subscription::factory()->create(['user_id' => $owner->id]);
    app()->forgetScopedInstances();

    $service = app(EntitlementService::class);

    expect($service->forWallet($wallet->id)->summary()->planId)->toBe($service->forUser($owner->id)->summary()->planId)
        ->and($service->forWallet($wallet->id)->allows(Feature::SharingEnabled))->toBeTrue()
        ->and($service->forUser($member->id)->allows(Feature::SharingEnabled))->toBeFalse();
});

test('after an ownership transfer it follows the new owner on the next request', function () {
    $owner = person();
    $member = person('grace@example.test');
    $wallet = ownedWallet($owner->id);
    addMember($wallet->id, $member->id, 'editor');
    Subscription::factory()->create(['user_id' => $owner->id]);
    app()->forgetScopedInstances();
    expect(app(EntitlementService::class)->forWallet($wallet->id)->allows(Feature::SharingEnabled))->toBeTrue();

    app(WalletService::class)->transferOwnership($owner->id, $wallet->id, $member->id, $wallet->fresh()->version, presentWallet());
    app()->forgetScopedInstances();

    expect(app(EntitlementService::class)->forWallet($wallet->id)->allows(Feature::SharingEnabled))->toBeFalse();
});

test('an unknown or deleted wallet throws, never silently the free plan', function () {
    $owner = person();
    $wallet = ownedWallet($owner->id);
    app()->forgetScopedInstances();

    expect(fn () => app(EntitlementService::class)->forWallet((string) Str::uuid7()))->toThrow(RuntimeException::class);

    DB::table('wallet_members')->where('wallet_id', $wallet->id)->update(['deleted_at' => now()]);
    expect(fn () => app(EntitlementService::class)->forWallet($wallet->id))->toThrow(RuntimeException::class);
});

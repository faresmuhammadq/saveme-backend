<?php

use App\Modules\Billing\Domain\Feature;
use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Billing\Infrastructure\Models\Plan;
use App\Modules\Identity\Infrastructure\Models\User;

/*
 * `FEATURE_NOT_ENTITLED` names the capability in the Problem member `feature`
 * (v1.9, spec 016): present whenever the refusing code knows it, absent
 * otherwise, and valid against `Problem` either way.
 */

test('the member is present, valid against Problem, on a refused invitation', function () {
    [, $credential] = walletCaller('free@example.test');
    $wallet = ownedWallet(User::query()->firstOrFail()->id);

    $response = sendInvitation($credential, $wallet->id)->assertForbidden();

    $response->assertValidResponse(403);
    expect($response->json('code'))->toBe('FEATURE_NOT_ENTITLED')
        ->and($response->json('feature'))->toBe('sharing.enabled');
});

test('the wallet quota refusal names wallets.max', function () {
    [$user, $credential] = walletCaller('ada@example.test');
    Plan::query()->where('code', 'free')->update(['features' => json_encode([
        'sharing.enabled' => false, 'sharing.max_members_per_wallet' => 1, 'export.enabled' => true, 'reports.advanced' => true, 'wallets.max' => 1,
    ])]);
    ownedWallet($user->id);

    $response = walletCall('PUT', $credential, '/wallets/'.Str::uuid7(), walletBody())->assertForbidden();

    $response->assertValidResponse(403);
    expect($response->json('feature'))->toBe('wallets.max');
});

test('a refusal that does not know the capability omits the member', function () {
    expect((new FeatureNotEntitled)->extensions)->toBe([])
        ->and((new FeatureNotEntitled(Feature::ExportEnabled))->extensions)->toBe(['feature' => 'export.enabled']);
});

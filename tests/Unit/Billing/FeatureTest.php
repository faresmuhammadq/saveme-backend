<?php

use App\Modules\Billing\Domain\Feature;

test('the capabilities are the fixed list', function () {
    expect(array_map(fn (Feature $f): string => $f->value, Feature::cases()))
        ->toBe(['sharing.enabled', 'sharing.max_members_per_wallet', 'sharing.max_members_per_budget', 'export.enabled', 'reports.advanced', 'wallets.max']);
});

test('only member and wallet counts are quotas, and denial is false or 0', function () {
    expect(array_values(array_map(fn (Feature $f): string => $f->value, array_filter(Feature::cases(), fn (Feature $f): bool => $f->isQuota()))))
        ->toBe(['sharing.max_members_per_wallet', 'sharing.max_members_per_budget', 'wallets.max'])
        ->and(Feature::SharingEnabled->deniedValue())->toBeFalse()
        ->and(Feature::WalletsMax->deniedValue())->toBe(0);
});

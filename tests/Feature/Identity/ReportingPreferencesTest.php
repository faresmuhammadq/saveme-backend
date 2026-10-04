<?php

use App\Modules\Identity\Application\Profiles\ReportingPreferences;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;

/*
 * The default currency of cross-wallet reports (spec 019, R-9).
 */

test('the profile default currency is served, and EGP without a profile', function () {
    $owner = person('owner@example.test');
    Profile::query()->where('user_id', $owner->id)->update(['default_currency' => 'USD']);
    $bare = User::factory()->create();

    $preferences = app(ReportingPreferences::class);

    expect($preferences->defaultCurrencyOf($owner->id))->toBe('USD')
        ->and($preferences->defaultCurrencyOf($bare->id))->toBe('EGP');
});

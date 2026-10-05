<?php

use App\Modules\Billing\Infrastructure\Database\Seeders\BillingSeeder;
use App\Modules\Billing\Infrastructure\Models\Plan;

/*
 * The catalogue seeder never undoes a back-office edit (spec 021, FR-039, R-6).
 */

test('a missing plan is inserted', function () {
    Plan::query()->where('code', 'plus_yearly')->delete();

    (new BillingSeeder)->run();

    expect(Plan::query()->where('code', 'plus_yearly')->sole()->prices[0]['amount_minor'])->toBe(129000);
});

test('an edited name, order, price, capability value and status survive a re-run', function () {
    $plan = Plan::query()->where('code', 'plus_monthly')->sole();
    $plan->update([
        'name' => ['en' => 'Renamed', 'ar' => 'اسم جديد'],
        'sort_order' => 40,
        'prices' => [['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 99900, 'currency' => 'USD']],
        'features' => [...$plan->features, 'wallets.max' => 7, 'sharing.max_members_per_wallet' => 2],
        'status' => 'retired',
    ]);

    (new BillingSeeder)->run();

    $plan->refresh();
    expect($plan->name['en'])->toBe('Renamed')
        ->and($plan->sort_order)->toBe(40)
        ->and($plan->prices[0]['amount_minor'])->toBe(99900)
        ->and($plan->features['wallets.max'])->toBe(7)
        ->and($plan->features['sharing.max_members_per_wallet'])->toBe(2)
        ->and($plan->status)->toBe('retired');
});

test('a capability key absent from a stored plan is added with the seeded value, others untouched', function () {
    $plan = Plan::query()->where('code', 'plus_monthly')->sole();
    $features = $plan->features;
    unset($features['reports.advanced']);
    $features['wallets.max'] = 3;
    $plan->update(['features' => $features]);

    (new BillingSeeder)->run();

    expect($plan->refresh()->features['reports.advanced'])->toBeTrue()
        ->and($plan->features['wallets.max'])->toBe(3);
});

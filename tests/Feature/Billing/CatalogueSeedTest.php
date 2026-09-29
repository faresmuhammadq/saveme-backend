<?php

use App\Modules\Billing\Infrastructure\Database\Seeders\BillingSeeder;
use App\Modules\Billing\Infrastructure\Models\Plan;
use Illuminate\Database\QueryException;

/*
 * The launch catalogue as reference data (spec 009, FR-002, FR-004, R-8).
 */

test('three active plans in order, with Free the only default', function () {
    expect(Plan::query()->where('status', 'active')->orderBy('sort_order')->pluck('code')->all())->toBe(['free', 'plus_monthly', 'plus_yearly'])
        ->and(Plan::query()->where('is_default', true)->pluck('code')->all())->toBe(['free']);
});

test('Free is limited only on sharing; Plus shares with five', function () {
    $free = Plan::query()->where('code', 'free')->sole();
    $plus = Plan::query()->where('code', 'plus_monthly')->sole();

    expect($free->features)->toEqual(['sharing.enabled' => false, 'sharing.max_members_per_wallet' => 1, 'export.enabled' => true, 'reports.advanced' => true, 'wallets.max' => null])
        ->and($plus->features['sharing.enabled'])->toBeTrue()
        ->and($plus->features['sharing.max_members_per_wallet'])->toBe(5);
});

test('prices are integer minor units in EGP', function () {
    expect(Plan::query()->where('code', 'plus_monthly')->sole()->prices)->toEqual([['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 14900, 'currency' => 'EGP']])
        ->and(Plan::query()->where('code', 'plus_yearly')->sole()->prices[0]['amount_minor'])->toBe(129000);
});

test('seeding again changes nothing', function () {
    $before = Plan::query()->orderBy('code')->get(['id', 'updated_at'])->toArray();

    (new BillingSeeder)->run();

    expect(Plan::query()->orderBy('code')->get(['id', 'updated_at'])->toArray())->toBe($before);
});

test('a plan missing a capability fails the seed, naming it', function () {
    $definition = BillingSeeder::definitions()[1];
    unset($definition['features']['reports.advanced']);

    expect(fn () => BillingSeeder::validate($definition))->toThrow(InvalidArgumentException::class, 'Plan [plus_monthly] does not declare capability [reports.advanced]');
});

test('a price in an unknown currency fails the seed', function () {
    $definition = BillingSeeder::definitions()[1];
    $definition['prices'][0]['currency'] = 'egp';

    expect(fn () => BillingSeeder::validate($definition))->toThrow(InvalidArgumentException::class);
});

test('a second active default is refused by the database', function () {
    expect(fn () => Plan::factory()->create(['is_default' => true]))->toThrow(QueryException::class);
});

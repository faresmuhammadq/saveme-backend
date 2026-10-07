<?php

use App\Modules\Billing\Application\Plans\PlanCatalogue;
use App\Modules\Billing\Infrastructure\Database\Seeders\BillingSeeder;
use App\Modules\Billing\Infrastructure\Models\Plan;

/*
 * A Play product id maps to a plan through a `google_play` price, seeded
 * create-missing from configuration (spec 024, FR-040 to FR-043, R-9).
 */

function playPrices(string $code): array
{
    return array_values(array_filter(Plan::query()->where('code', $code)->firstOrFail()->prices, fn (array $price): bool => $price['channel'] === 'google_play'));
}

function configurePlay(array $monthly = [], array $yearly = []): void
{
    config(['billing.google_play.products' => [
        'plus_monthly' => ['product_id' => 'plus_monthly', 'amount_minor' => 14900, 'currency' => 'EGP', ...$monthly],
        'plus_yearly' => ['product_id' => 'plus_yearly', 'amount_minor' => 129000, 'currency' => 'EGP', ...$yearly],
    ]]);
}

test('with no amount configured nothing is seeded and no product maps', function () {
    expect(playPrices('plus_monthly'))->toBe([])
        ->and(app(PlanCatalogue::class)->planForPlayProduct('plus_monthly'))->toBeNull()
        ->and(app(PlanCatalogue::class)->playProductIds())->toBe([]);
});

test('the seeder adds each plan\'s google_play price once from configuration, as integer minor units', function () {
    configurePlay();

    (new BillingSeeder)->run();
    (new BillingSeeder)->run();

    expect(playPrices('plus_monthly'))->toEqual([['channel' => 'google_play', 'product_id' => 'plus_monthly', 'amount_minor' => 14900, 'currency' => 'EGP']])
        ->and(playPrices('plus_yearly'))->toHaveCount(1)
        ->and(Plan::query()->where('code', 'plus_monthly')->sole()->prices)->toHaveCount(2); // instapay + google_play
});

test('the catalogue maps a product id to its plan, and lists them', function () {
    configurePlay();
    (new BillingSeeder)->run();

    $catalogue = app(PlanCatalogue::class);

    expect($catalogue->planForPlayProduct('plus_yearly')->id)->toBe(planIdByCode('plus_yearly'))
        ->and($catalogue->planForPlayProduct('plus_monthly')->id)->toBe(planIdByCode('plus_monthly'))
        ->and($catalogue->planForPlayProduct('unknown'))->toBeNull()
        ->and($catalogue->playProductIds())->toEqualCanonicalizing(['plus_monthly', 'plus_yearly']);
});

test('an operator-edited plan is never touched', function () {
    $plan = Plan::query()->where('code', 'plus_monthly')->sole();
    $plan->prices = [['channel' => 'google_play', 'product_id' => 'edited_by_operator', 'amount_minor' => 1, 'currency' => 'EGP']];
    $plan->save();
    configurePlay();

    (new BillingSeeder)->run();

    expect(playPrices('plus_monthly'))->toEqual([['channel' => 'google_play', 'product_id' => 'edited_by_operator', 'amount_minor' => 1, 'currency' => 'EGP']]);
});

test('an unset amount, an unknown currency or a missing product id seeds nothing', function (array $monthly) {
    configurePlay($monthly);

    (new BillingSeeder)->run();

    expect(playPrices('plus_monthly'))->toBe([]);
})->with([
    'no amount' => [['amount_minor' => null]],
    'a float amount' => [['amount_minor' => 149.5]],
    'unknown currency' => [['currency' => 'XXX']],
    'no product id' => [['product_id' => '']],
]);

test('one product id on two plans throws', function () {
    configurePlay(yearly: ['product_id' => 'plus_monthly']);

    expect(fn () => (new BillingSeeder)->run())->toThrow(InvalidArgumentException::class);
});

test('a product id already on another stored plan throws', function () {
    $other = Plan::query()->where('code', 'plus_yearly')->sole();
    $other->prices = [...$other->prices, ['channel' => 'google_play', 'product_id' => 'plus_monthly', 'amount_minor' => 5, 'currency' => 'EGP']];
    $other->save();
    configurePlay();

    expect(fn () => (new BillingSeeder)->run())->toThrow(InvalidArgumentException::class);
});

test('a retired plan still maps its product', function () {
    mapPlayProducts();
    Plan::query()->where('code', 'plus_monthly')->update(['status' => 'retired']);

    expect(app(PlanCatalogue::class)->planForPlayProduct('plus_monthly')->status)->toBe('retired')
        ->and(app(PlanCatalogue::class)->playProductIds())->toContain('plus_monthly');
});

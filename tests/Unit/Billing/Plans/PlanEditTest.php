<?php

use App\Modules\Billing\Domain\PlanCode;
use App\Modules\Billing\Domain\Plans\PlanChangeSet;
use App\Modules\Billing\Domain\Plans\PlanEdit;
use App\Modules\Billing\Domain\Plans\PlanRecord;
use App\Modules\Billing\Domain\Refusals\NothingToSave;
use App\Modules\Billing\Domain\Refusals\PlanEditInvalid;

/*
 * The editing rules of data-model.md (spec 021, FR-032 to FR-035), with no
 * framework and no database.
 */

function storedPlan(bool $isDefault = false): PlanRecord
{
    return new PlanRecord(
        'plan-1',
        new PlanCode($isDefault ? 'free' : 'plus_monthly'),
        ['en' => 'Plus', 'ar' => 'بلس'],
        'monthly',
        null,
        $isDefault,
        'active',
        2,
        ['sharing.enabled' => ! $isDefault, 'sharing.max_members_per_wallet' => $isDefault ? 1 : 5, 'export.enabled' => true, 'reports.advanced' => true, 'wallets.max' => null],
        $isDefault ? [] : [
            ['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 14900, 'currency' => 'EGP'],
            ['channel' => 'google_play', 'product_id' => 'plus_monthly_gp', 'amount_minor' => 15000, 'currency' => 'EGP'],
        ],
        'v1',
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function draftFor(PlanRecord $plan, array $overrides = []): PlanChangeSet
{
    $price = collect($plan->prices)->firstWhere('channel', 'instapay');

    return PlanChangeSet::fromDraft([
        'name' => $plan->name,
        'sort_order' => $plan->sortOrder,
        'instapay_price' => $price === null ? null : ['amount_minor' => $price['amount_minor'], 'currency' => $price['currency']],
        'features' => $plan->features,
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return list<string>
 */
function invalidFields(PlanRecord $plan, array $overrides): array
{
    try {
        PlanEdit::of($plan, draftFor($plan, $overrides));
    } catch (PlanEditInvalid $invalid) {
        return $invalid->fields;
    } catch (NothingToSave) {
        return [];
    }

    return [];
}

test('an identical draft is nothing to save', function () {
    expect(fn () => PlanEdit::of(storedPlan(), draftFor(storedPlan())))->toThrow(NothingToSave::class);
});

test('names are trimmed and 1 to 40 characters counted as characters', function () {
    $plan = storedPlan();

    expect(PlanEdit::of($plan, draftFor($plan, ['name' => ['en' => '  Plus Pro  ', 'ar' => 'بلس']]))->columns['name']['en'])->toBe('Plus Pro')
        ->and(invalidFields($plan, ['name' => ['en' => '   ', 'ar' => 'بلس']]))->toBe(['name.en'])
        ->and(invalidFields($plan, ['name' => ['en' => 'Plus', 'ar' => str_repeat('ب', 41)]]))->toBe(['name.ar'])
        ->and(invalidFields($plan, ['name' => ['en' => str_repeat('x', 40), 'ar' => str_repeat('ب', 40)]]))->toBe([]);
});

test('the display order is an integer from 1 to 999', function (mixed $order, bool $valid) {
    expect(invalidFields(storedPlan(), ['sort_order' => $order]))->toBe($valid ? [] : ['sort_order']);
})->with([[0, false], [1, true], [999, true], [1000, false], ['5', false], [2.5, false], [null, false]]);

test('the InstaPay price is removed with null or 100 to 10,000,000 minor units in a known currency', function (mixed $price, bool $valid) {
    expect(invalidFields(storedPlan(), ['instapay_price' => $price]))->toBe($valid ? [] : ['prices.instapay']);
})->with([
    [null, true],
    [['amount_minor' => 99, 'currency' => 'EGP'], false],
    [['amount_minor' => 100, 'currency' => 'EGP'], true],
    [['amount_minor' => 10_000_000, 'currency' => 'USD'], true],
    [['amount_minor' => 10_000_001, 'currency' => 'EGP'], false],
    [['amount_minor' => 149.5, 'currency' => 'EGP'], false],
    [['amount_minor' => '14900', 'currency' => 'EGP'], false],
    [['amount_minor' => 14900, 'currency' => 'egp'], false],
    [['amount_minor' => 14900, 'currency' => 'XXX'], false],
]);

test('flags are booleans and quotas are null or 1 to 1,000', function () {
    $plan = storedPlan();

    expect(invalidFields($plan, ['features' => [...$plan->features, 'export.enabled' => 'yes']]))->toBe(['features.export.enabled'])
        ->and(invalidFields($plan, ['features' => [...$plan->features, 'wallets.max' => 0]]))->toBe(['features.wallets.max'])
        ->and(invalidFields($plan, ['features' => [...$plan->features, 'wallets.max' => 1001]]))->toBe(['features.wallets.max'])
        ->and(invalidFields($plan, ['features' => [...$plan->features, 'wallets.max' => 1000]]))->toBe([])
        ->and(invalidFields($plan, ['features' => [...$plan->features, 'wallets.max' => null]]))->toBe([])
        ->and(invalidFields($plan, ['features' => [...$plan->features, 'sharing.max_members_per_wallet' => true]]))->toBe(['features.sharing.max_members_per_wallet'])
        ->and(invalidFields($plan, ['features' => [...$plan->features, 'made.up' => true]]))->toBe(['features.made.up']);
});

test('the default plan keeps every capability but sharing on or unlimited', function () {
    $free = storedPlan(true);
    $features = $free->features;

    expect(invalidFields($free, ['features' => [...$features, 'export.enabled' => false]]))->toBe(['features.export.enabled'])
        ->and(invalidFields($free, ['features' => [...$features, 'reports.advanced' => false]]))->toBe(['features.reports.advanced'])
        ->and(invalidFields($free, ['features' => [...$features, 'wallets.max' => 5]]))->toBe(['features.wallets.max'])
        ->and(invalidFields($free, ['features' => [...$features, 'sharing.enabled' => true, 'sharing.max_members_per_wallet' => 3]]))->toBe([]);
});

test('every invalid field is reported together, sorted', function () {
    expect(invalidFields(storedPlan(), ['name' => ['en' => '', 'ar' => ''], 'sort_order' => 0, 'instapay_price' => ['amount_minor' => 1, 'currency' => 'EGP']]))
        ->toBe(['name.ar', 'name.en', 'prices.instapay', 'sort_order']);
});

test('the changed field names are listed sorted, and unchanged fields are left alone', function () {
    $plan = storedPlan();

    $edit = PlanEdit::of($plan, draftFor($plan, [
        'sort_order' => 7,
        'name' => ['en' => 'Plus+', 'ar' => 'بلس ٢'],
        'instapay_price' => ['amount_minor' => 19900, 'currency' => 'EGP'],
        'features' => [...$plan->features, 'export.enabled' => false, 'wallets.max' => 10],
    ]));

    expect($edit->fields)->toBe(['features.export.enabled', 'features.wallets.max', 'name.ar', 'name.en', 'prices.instapay', 'sort_order'])
        ->and($edit->columns['features']['reports.advanced'])->toBeTrue()
        ->and($edit->columns['features']['sharing.max_members_per_wallet'])->toBe(5);
});

test('a price change keeps the Google Play entries, a removal drops only InstaPay, an addition appends', function () {
    $plan = storedPlan();
    $googlePlay = $plan->prices[1];

    $changed = PlanEdit::of($plan, draftFor($plan, ['instapay_price' => ['amount_minor' => 19900, 'currency' => 'EGP']]));
    $removed = PlanEdit::of($plan, draftFor($plan, ['instapay_price' => null]));
    $withoutPrices = new PlanRecord('plan-2', new PlanCode('plus_yearly'), $plan->name, 'yearly', null, false, 'active', 3, $plan->features, [$googlePlay], 'v1');
    $added = PlanEdit::of($withoutPrices, draftFor($withoutPrices, ['instapay_price' => ['amount_minor' => 5000, 'currency' => 'EGP']]));

    expect($changed->columns['prices'])->toBe([['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 19900, 'currency' => 'EGP'], $googlePlay])
        ->and($removed->columns['prices'])->toBe([$googlePlay])
        ->and($removed->fields)->toBe(['prices.instapay'])
        ->and($added->columns['prices'])->toBe([$googlePlay, ['channel' => 'instapay', 'product_id' => null, 'amount_minor' => 5000, 'currency' => 'EGP']])
        ->and($added->fields)->toBe(['prices.instapay']);
});

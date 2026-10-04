<?php

use App\Modules\Reporting\Domain\CategoryBreakdown;
use App\Modules\Reporting\Domain\CategoryItem;
use App\Modules\Reporting\Domain\CategoryTotal;
use App\Modules\Shared\Domain\Money\Money;

/*
 * The by-category arithmetic (spec 019, FR-011, FR-012): labels are keys,
 * ties sort by label, the total is the exact sum of the items and no share
 * exists.
 */

function total(string $kind, string $id, ?string $key, int $minor): CategoryTotal
{
    return new CategoryTotal($kind, $id, $key, Money::of($minor, 'EGP'));
}

function labels(array $items): array
{
    return array_map(fn (CategoryItem $item): string => $item->label, $items);
}

test('a platform category is labelled by its system key and a person\'s by its id', function () {
    $breakdown = CategoryBreakdown::from([total('expense', 'id-1', 'groceries', 5000), total('expense', 'id-2', null, 700)], null, 'EGP');

    expect(labels($breakdown->expenses->items))->toBe(['groceries', 'id-2'])
        ->and($breakdown->expenses->items[0]->systemKey)->toBe('groceries')
        ->and($breakdown->expenses->items[1]->systemKey)->toBeNull();
});

test('categories with equal names stay separate because they are grouped by id', function () {
    $breakdown = CategoryBreakdown::from([total('expense', 'mine', null, 300), total('expense', 'partner', null, 200)], null, 'EGP');

    expect($breakdown->expenses->items)->toHaveCount(2)->and($breakdown->expenses->total->amountMinor)->toBe(500);
});

test('items sort by amount descending, then label ascending, and the total is their exact sum', function () {
    $breakdown = CategoryBreakdown::from([
        total('expense', 'c', 'transport', 1000),
        total('expense', 'a', 'groceries', 1000),
        total('expense', 'b', 'dining', 2500),
        total('expense', 'd', 'bills', 1),
    ], null, 'EGP');

    expect(labels($breakdown->expenses->items))->toBe(['dining', 'groceries', 'transport', 'bills'])
        ->and($breakdown->expenses->total->amountMinor)->toBe(4501)
        ->and($breakdown->expenses->planned)->toBeNull();
});

test('income and expenses are separate groups and zero items are omitted', function () {
    $breakdown = CategoryBreakdown::from([
        total('income', 'salary-id', 'salary', 900000),
        total('expense', 'z', 'groceries', 0),
        total('expense', 'y', 'transport', 4000),
    ], null, 'EGP');

    expect(labels($breakdown->income->items))->toBe(['salary'])
        ->and($breakdown->income->total->amountMinor)->toBe(900000)
        ->and(labels($breakdown->expenses->items))->toBe(['transport'])
        ->and(CategoryBreakdown::from([], null, 'EGP')->expenses->total->isZero())->toBeTrue();
});

test('a planned-only item appears with a zero value only when planned figures are asked for', function () {
    $actual = [total('expense', 'g', 'groceries', 4000)];
    $planned = [total('expense', 'r', 'rent', 450000), total('expense', 'g', 'groceries', 1000)];

    $without = CategoryBreakdown::from($actual, null, 'EGP');
    $with = CategoryBreakdown::from($actual, $planned, 'EGP');

    expect(labels($without->expenses->items))->toBe(['groceries'])
        ->and(labels($with->expenses->items))->toBe(['groceries', 'rent'])
        ->and($with->expenses->items[0]->planned?->amountMinor)->toBe(1000)
        ->and($with->expenses->items[1]->value->amountMinor)->toBe(0)
        ->and($with->expenses->items[1]->planned?->amountMinor)->toBe(450000)
        ->and($with->expenses->planned?->amountMinor)->toBe(451000)
        ->and($with->expenses->total->amountMinor)->toBe(4000);
});

test('with planned figures asked for and none scheduled, every planned amount is zero rather than null', function () {
    $with = CategoryBreakdown::from([total('expense', 'g', 'groceries', 4000)], [], 'EGP');

    expect($with->expenses->items[0]->planned?->amountMinor)->toBe(0)->and($with->expenses->planned?->amountMinor)->toBe(0);
});

test('an item carries no share member', function () {
    expect(array_keys(get_object_vars(CategoryBreakdown::from([total('expense', 'g', 'groceries', 1)], null, 'EGP')->expenses->items[0])))
        ->toBe(['label', 'categoryId', 'systemKey', 'value', 'planned']);
});

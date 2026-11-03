<?php

use App\Modules\Wallets\Infrastructure\Database\Seeders\PlatformCategorySeeder;
use App\Modules\Wallets\Infrastructure\Models\Category;

/*
 * The platform default set is seeded idempotently (spec 011, FR-016, FR-020, R-4).
 * Every test already starts with the seeded set.
 */

function platformRows(): array
{
    return Category::query()->whereNull('user_id')->where('internal', false)->orderBy('position')->get()->keyBy('system_key')->all();
}

test('a seeded database holds the 26 platform categories, both names each', function () {
    $rows = platformRows();

    expect($rows)->toHaveCount(26)
        ->and($rows['groceries']->names)->toBe(['ar' => 'بقالة', 'en' => 'Groceries'])
        ->and($rows['groceries']->version)->toBe(1)
        ->and($rows['groceries']->archived_at)->toBeNull();
});

test('running again changes nothing: same ids, updated_at and version', function () {
    $before = platformRows();

    (new PlatformCategorySeeder)->run();

    $after = platformRows();
    expect($after)->toHaveCount(26);
    foreach ($before as $key => $row) {
        expect($after[$key]->id)->toBe($row->id)
            ->and($after[$key]->version)->toBe($row->version)
            ->and($after[$key]->updated_at->equalTo($row->updated_at))->toBeTrue();
    }
});

test('a changed name updates in place, keeping the id and bumping version and updated_at', function () {
    $before = platformRows()['groceries'];
    $definitions = PlatformCategorySeeder::definitions();
    $definitions[0]['names']['en'] = 'Supermarket';

    (new PlatformCategorySeeder)->apply($definitions);

    $after = platformRows()['groceries'];
    expect($after->id)->toBe($before->id)
        ->and($after->names['en'])->toBe('Supermarket')
        ->and($after->names['ar'])->toBe('بقالة')
        ->and($after->version)->toBe(2)
        ->and($after->updated_at->greaterThan($before->updated_at))->toBeTrue();
});

test('a key dropped from the definitions is retired once, never deleted; re-adding un-retires it', function () {
    $before = platformRows()['other_income'];
    $dropped = array_slice(PlatformCategorySeeder::definitions(), 0, 25);

    (new PlatformCategorySeeder)->apply($dropped);
    $retired = platformRows()['other_income'];

    expect(platformRows())->toHaveCount(26)
        ->and($retired->archived_at)->not->toBeNull()
        ->and($retired->version)->toBe(2);

    (new PlatformCategorySeeder)->apply($dropped);
    expect(platformRows()['other_income']->version)->toBe(2)
        ->and(platformRows()['other_income']->archived_at->equalTo($retired->archived_at))->toBeTrue();

    (new PlatformCategorySeeder)->run();
    $back = platformRows()['other_income'];
    expect($back->id)->toBe($before->id)
        ->and($back->archived_at)->toBeNull()
        ->and($back->version)->toBe(3);
});

test('a retired category leaves its position to a newly defined one', function () {
    $dropped = array_slice(PlatformCategorySeeder::definitions(), 1);
    (new PlatformCategorySeeder)->apply($dropped);

    $replacement = [...$dropped, ['system_key' => 'brand_new', 'kind' => 'expense', 'names' => ['en' => 'New', 'ar' => 'جديد'], 'icon' => 'dots', 'color' => '#757575', 'position' => 1]];
    (new PlatformCategorySeeder)->apply($replacement);

    $rows = platformRows();
    expect($rows['brand_new']->position)->toBe(1)
        ->and($rows['groceries']->archived_at)->not->toBeNull()
        ->and($rows['groceries']->position)->not->toBe(1);
});

test('a kind change throws and changes nothing', function () {
    $definitions = PlatformCategorySeeder::definitions();
    $definitions[0]['kind'] = 'income';
    $definitions[1]['names']['en'] = 'Changed in the same run';

    expect(fn () => (new PlatformCategorySeeder)->apply($definitions))->toThrow(InvalidArgumentException::class);

    expect(platformRows()['groceries']->kind)->toBe('expense')
        ->and(platformRows()['dining_out']->names['en'])->toBe('Dining out');
});

test('positions may swap between existing keys', function () {
    $definitions = PlatformCategorySeeder::definitions();
    [$definitions[0]['position'], $definitions[1]['position']] = [2, 1];

    (new PlatformCategorySeeder)->apply($definitions);

    expect(platformRows()['groceries']->position)->toBe(2)->and(platformRows()['dining_out']->position)->toBe(1);
});

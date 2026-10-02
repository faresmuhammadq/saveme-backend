<?php

use App\Modules\Wallets\Infrastructure\Database\Seeders\PlatformCategorySeeder;

test('the definitions are exactly the 26 rows of spec 011 section 3', function () {
    $definitions = PlatformCategorySeeder::definitions();

    expect($definitions)->toHaveCount(26)
        ->and(array_column($definitions, 'position'))->toBe(range(1, 26))
        ->and(array_column($definitions, 'system_key'))->toHaveCount(26)
        ->and(array_unique(array_column($definitions, 'system_key')))->toHaveCount(26)
        ->and(array_count_values(array_column($definitions, 'kind')))->toBe(['expense' => 19, 'income' => 7])
        ->and(array_slice(array_column($definitions, 'system_key'), 0, 3))->toBe(['groceries', 'dining_out', 'transport'])
        ->and($definitions[0])->toBe(['system_key' => 'groceries', 'kind' => 'expense', 'names' => ['en' => 'Groceries', 'ar' => 'بقالة'], 'icon' => 'cart', 'color' => '#2E7D32', 'position' => 1])
        ->and($definitions[25]['system_key'])->toBe('other_income');

    PlatformCategorySeeder::validateAll($definitions);
});

test('validation refuses a bad key, a bad name, a bad colour and duplicates', function (array $changes) {
    $definition = [...PlatformCategorySeeder::definitions()[0], ...$changes];

    expect(fn () => PlatformCategorySeeder::validate($definition))->toThrow(InvalidArgumentException::class);
})->with([
    'bad key' => [['system_key' => 'Bad Key']],
    'blank english name' => [['names' => ['en' => ' ', 'ar' => 'بقالة']]],
    'missing arabic name' => [['names' => ['en' => 'Groceries']]],
    'fifty-one character name' => [['names' => ['en' => str_repeat('a', 51), 'ar' => 'بقالة']]],
    'lowercase colour' => [['color' => '#2e7d32']],
    'bad icon' => [['icon' => 'Bad Icon']],
    'unknown kind' => [['kind' => 'transfer']],
]);

test('duplicate keys or positions are refused', function () {
    $first = PlatformCategorySeeder::definitions()[0];
    $second = [...PlatformCategorySeeder::definitions()[1]];

    expect(fn () => PlatformCategorySeeder::validateAll([$first, [...$second, 'system_key' => $first['system_key']]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PlatformCategorySeeder::validateAll([$first, [...$second, 'position' => $first['position']]]))->toThrow(InvalidArgumentException::class);
});

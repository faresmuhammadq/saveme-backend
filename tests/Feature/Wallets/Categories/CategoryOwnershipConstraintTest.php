<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * A category is a person's or the platform's, never both and never neither
 * (spec 011 section 4; ADR-0014; FR-015).
 */

beforeEach(function () {
    // Every test starts with the seeded platform set; these tests insert their own rows.
    DB::table('categories')->delete();
});

function personRow(array $changes = []): array
{
    return [
        'id' => (string) Str::uuid7(), 'user_id' => (string) Str::uuid7(), 'system_key' => null, 'kind' => 'expense',
        'name' => 'Coffee', 'names' => null, 'icon' => null, 'color' => null, 'position' => null, 'parent_id' => null,
        'archived_at' => null, 'version' => 1, 'change_seq' => 5, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ...$changes,
    ];
}

function platformRow(array $changes = []): array
{
    return [
        'id' => (string) Str::uuid7(), 'user_id' => null, 'system_key' => 'groceries', 'kind' => 'expense',
        'name' => null, 'names' => json_encode(['ar' => 'بقالة', 'en' => 'Groceries']), 'icon' => 'cart', 'color' => '#2E7D32', 'position' => 1, 'parent_id' => null,
        'archived_at' => null, 'version' => 1, 'change_seq' => null, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ...$changes,
    ];
}

test('one valid row of each kind inserts', function () {
    DB::table('categories')->insert(personRow());
    DB::table('categories')->insert(platformRow());

    expect(DB::table('categories')->count())->toBe(2);
});

test('a row that breaks either shape fails at the database', function (array $row) {
    expect(fn () => DB::table('categories')->insert($row))->toThrow(QueryException::class);
})->with([
    'both owners' => fn () => [personRow(['system_key' => 'groceries'])],
    'neither owner' => fn () => [personRow(['user_id' => null])],
    'platform without names' => fn () => [platformRow(['names' => null])],
    'platform with a person name' => fn () => [platformRow(['name' => 'X'])],
    'person without change_seq' => fn () => [personRow(['change_seq' => null])],
    'platform with deleted_at' => fn () => [platformRow(['deleted_at' => now()])],
    'person with a position' => fn () => [personRow(['position' => 3])],
    'person with names' => fn () => [personRow(['names' => json_encode(['ar' => 'a', 'en' => 'b'])])],
    'a parent' => fn () => [personRow(['parent_id' => (string) Str::uuid7()])],
    'blank person name' => fn () => [personRow(['name' => '  '])],
    'unknown kind' => fn () => [personRow(['kind' => 'transfer'])],
    'lowercase colour' => fn () => [personRow(['color' => '#6d4c41'])],
    'platform without icon' => fn () => [platformRow(['icon' => null])],
    'malformed system key' => fn () => [platformRow(['system_key' => 'Groceries'])],
]);

test('system keys and platform positions are unique', function () {
    DB::table('categories')->insert(platformRow());

    expect(fn () => DB::table('categories')->insert(platformRow(['position' => 2])))->toThrow(QueryException::class);
});

test('a platform position is unique among platform rows only', function () {
    DB::table('categories')->insert(platformRow());
    DB::table('categories')->insert(personRow());

    expect(DB::table('categories')->count())->toBe(2)
        ->and(fn () => DB::table('categories')->insert(platformRow(['system_key' => 'transport'])))->toThrow(QueryException::class);
});

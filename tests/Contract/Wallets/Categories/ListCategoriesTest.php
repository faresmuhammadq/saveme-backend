<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Facades\DB;

/*
 * GET /categories (spec 011, US1).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
});

test('lists only my live categories, archived included', function () {
    $other = person('grace@example.test');
    ownedCategory($this->user->id, ['name' => 'Mine']);
    ownedCategory($this->user->id, ['name' => 'Old', 'archived_at' => now()]);
    ownedCategory($this->user->id, ['name' => 'Gone', 'deleted_at' => now()]);
    ownedCategory($other->id, ['name' => 'Hers']);

    $response = walletCall('GET', $this->credential, '/categories')->assertValidRequest()->assertValidResponse(200);

    $byName = collect($response->json('data'))->keyBy('name');
    expect($byName->keys()->sort()->values()->all())->toBe(['Mine', 'Old'])
        ->and($byName['Old']['archived_at'])->not->toBeNull()
        ->and($byName['Mine']['user_id'])->toBe($this->user->id)
        ->and($byName['Mine']['in_use'])->toBeFalse()
        ->and(array_keys($byName['Mine']))->toBe(['id', 'user_id', 'version', 'change_seq', 'created_at', 'updated_at', 'deleted_at', 'kind', 'name', 'icon', 'color', 'archived_at', 'in_use'])
        ->and($response->json('meta'))->toMatchArray(['has_more' => false, 'next_cursor' => null]);
});

test('platform categories are never in the list', function () {
    walletCall('GET', $this->credential, '/categories')->assertValidResponse(200)->assertJsonPath('data', []);
});

test('in_use comes from the usage participants', function () {
    $used = ownedCategory($this->user->id, ['name' => 'Used']);
    ownedCategory($this->user->id, ['name' => 'Spare']);
    fakeCategoryUsage([$used->id]);

    $byName = collect(walletCall('GET', $this->credential, '/categories')->assertValidResponse(200)->json('data'))->keyBy('name');

    expect($byName['Used']['in_use'])->toBeTrue()->and($byName['Spare']['in_use'])->toBeFalse();
});

test('sorting by name is in byte order, descending by created_at works', function () {
    ownedCategory($this->user->id, ['name' => 'b', 'created_at' => now()->subDays(2)]);
    ownedCategory($this->user->id, ['name' => 'B', 'created_at' => now()->subDays(1)]);
    ownedCategory($this->user->id, ['name' => 'a', 'created_at' => now()]);

    $names = fn (string $query) => collect(walletCall('GET', $this->credential, '/categories'.$query)->assertValidResponse(200)->json('data'))->pluck('name')->all();

    expect($names('?sort=name'))->toBe(['B', 'a', 'b'])
        ->and($names('?sort=-created_at'))->toBe(['a', 'B', 'b'])
        ->and($names(''))->toBe(['b', 'B', 'a']);
});

test('paging with limit and next_cursor visits every category once', function () {
    foreach (['a', 'b', 'c'] as $name) {
        ownedCategory($this->user->id, ['name' => $name]);
    }

    $first = walletCall('GET', $this->credential, '/categories?limit=1&sort=name')->assertValidResponse(200);
    expect($first->json('meta.has_more'))->toBeTrue();

    $seen = [$first->json('data.0.name')];
    $cursor = $first->json('meta.next_cursor');
    while ($cursor !== null) {
        $page = walletCall('GET', $this->credential, '/categories?limit=1&sort=name&cursor='.$cursor)->assertValidResponse(200);
        $seen[] = $page->json('data.0.name');
        $cursor = $page->json('meta.next_cursor');
    }

    expect($seen)->toBe(['a', 'b', 'c']);
});

test('limit goes up to 200', function () {
    walletCall('GET', $this->credential, '/categories?limit=200')->assertValidResponse(200)->assertJsonPath('meta.limit', 200);
    walletCall('GET', $this->credential, '/categories?limit=999')->assertOk()->assertJsonPath('meta.limit', 200);
});

test('unsupported filters and sorts, and bad cursors, are 400', function () {
    withoutSpectatorRequestValidation();

    walletCall('GET', $this->credential, '/categories?filter[kind]=income')->assertStatus(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, '/categories?sort=color')->assertStatus(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    walletCall('GET', $this->credential, '/categories?cursor=garbage')->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
    walletCall('GET', $this->credential, '/categories?limit=0')->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
});

test('with no categories the answer is 200 with an empty list', function () {
    walletCall('GET', $this->credential, '/categories')->assertValidResponse(200)->assertJsonPath('data', []);
});

test('it needs a credential', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/categories')->assertValidResponse(401);
});

test('200 categories cost a constant number of queries', function () {
    $queriesFor = function (): int {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        walletCall('GET', $this->credential, '/categories?limit=200')->assertOk();

        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"categories"')));
    };

    Category::factory()->count(2)->create(['user_id' => $this->user->id]);
    $few = $queriesFor();

    Category::factory()->count(198)->create(['user_id' => $this->user->id]);
    $many = $queriesFor();

    expect($many)->toBe($few);
});

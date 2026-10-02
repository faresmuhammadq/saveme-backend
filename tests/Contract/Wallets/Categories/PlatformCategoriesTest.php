<?php

use App\Modules\Wallets\Infrastructure\Models\Category;

/*
 * GET /platform-categories (spec 011, US1; FR-015 to FR-019).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
});

test('the whole set is 26 entries in position order with one page of meta', function () {
    $response = walletCall('GET', $this->credential, '/platform-categories')
        ->assertValidRequest()
        ->assertValidResponse(200);

    $data = $response->json('data');
    expect($data)->toHaveCount(26)
        ->and(array_column($data, 'position'))->toBe(range(1, 26))
        ->and($data[0])->toMatchArray(['system_key' => 'groceries', 'kind' => 'expense', 'names' => ['ar' => 'بقالة', 'en' => 'Groceries'], 'icon' => 'cart', 'color' => '#2E7D32', 'archived_at' => null])
        ->and(array_keys($data[0]))->toBe(['id', 'system_key', 'kind', 'names', 'icon', 'color', 'position', 'archived_at', 'updated_at'])
        ->and($response->json('meta'))->toMatchArray(['next_cursor' => null, 'has_more' => false, 'limit' => 26]);
});

test('both names are present whatever Accept-Language asks for', function () {
    $data = fn (string $language) => test()->withHeaders([...asCredential($this->credential), 'Accept-Language' => $language])
        ->getJson('/api/v1/platform-categories')
        ->assertValidResponse(200)
        ->json('data');

    expect($data('ar'))->toBe($data('en'));
});

test('a retired entry is still listed, marked with archived_at', function () {
    Category::query()->where('system_key', 'fuel')->update(['archived_at' => now()]);

    $fuel = collect(walletCall('GET', $this->credential, '/platform-categories')->assertValidResponse(200)->json('data'))->firstWhere('system_key', 'fuel');

    expect($fuel['archived_at'])->not->toBeNull();
});

test('it needs a credential', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/platform-categories')->assertValidResponse(401);
});

test('an unverified person can read', function () {
    [, $credential] = walletCaller('grace@example.test', verified: false);

    walletCall('GET', $credential, '/platform-categories')->assertValidResponse(200);
});

test('no person writes a platform category: any other method is 405 with Allow GET', function (string $method) {
    $response = walletCall($method, $this->credential, '/platform-categories', ['name' => 'X'])
        ->assertStatus(405)
        ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');

    expect($response->headers->get('Allow'))->toContain('GET');
})->with(['PUT', 'POST', 'PATCH', 'DELETE']);

test('a platform id is not writable through the category endpoints', function () {
    $platform = Category::query()->whereNull('user_id')->firstOrFail();

    walletCall('PATCH', $this->credential, '/categories/'.$platform->id, ['name' => 'Mine', 'version' => 1])->assertStatus(404);
    walletCall('DELETE', $this->credential, '/categories/'.$platform->id, ['version' => 1])->assertStatus(404);
    walletCall('GET', $this->credential, '/categories/'.$platform->id)->assertStatus(404);

    expect(Category::query()->find($platform->id)->version)->toBe(1);
});

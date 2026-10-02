<?php

use App\Modules\Wallets\Infrastructure\Models\Category;

/*
 * GET /categories/{id} (spec 011, US1 and US2): the owner only.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
});

test('the owner reads the category', function () {
    $category = ownedCategory($this->user->id, ['name' => 'Coffee', 'icon' => 'cup', 'color' => '#6D4C41']);

    walletCall('GET', $this->credential, '/categories/'.$category->id)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.id', $category->id)
        ->assertJsonPath('data.name', 'Coffee')
        ->assertJsonPath('data.user_id', $this->user->id);
});

test('the owner reads a tombstone with deleted_at set', function () {
    $category = ownedCategory($this->user->id, ['deleted_at' => now()]);

    walletCall('GET', $this->credential, '/categories/'.$category->id)
        ->assertValidResponse(200)
        ->assertJsonPath('data.deleted_at', fn ($at) => $at !== null);
});

test("another person's category, a platform category and a wallet are 404", function () {
    $other = person('grace@example.test');
    $hers = ownedCategory($other->id);
    $platform = Category::query()->whereNull('user_id')->firstOrFail();
    $wallet = ownedWallet($this->user->id);

    foreach ([$hers->id, $platform->id, $wallet->id] as $id) {
        walletCall('GET', $this->credential, '/categories/'.$id)->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
    }
});

test('a partner in a shared wallet gets no right over my category', function () {
    $partner = person('grace@example.test');
    $category = ownedCategory($this->user->id);
    $wallet = ownedWallet($this->user->id);
    addMember($wallet->id, $partner->id, 'editor');
    $credential = signInAs('grace@example.test')->json('data.credential');

    walletCall('GET', $credential, '/categories/'.$category->id)->assertStatus(404);
    walletCall('PATCH', $credential, '/categories/'.$category->id, ['name' => 'Hers', 'version' => 1])->assertStatus(404);
    expect($category->fresh()->name)->toBe($category->name);
});

test('an id that is not a UUIDv7 is 422 INVALID_ID', function () {
    walletCall('GET', $this->credential, '/categories/not-an-id')->assertStatus(422)->assertJsonPath('code', 'INVALID_ID');
});

test('it needs a credential', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/categories/0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70')->assertValidResponse(401);
});

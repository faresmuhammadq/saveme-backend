<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Testing\TestResponse;

/*
 * POST /categories/{id}/archive (spec 011, US3).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->category = ownedCategory($this->user->id);
});

function archiveCall(string $credential, string $id, array $body = ['version' => 1]): TestResponse
{
    return walletCall('POST', $credential, '/categories/'.$id.'/archive', $body);
}

test('archiving sets archived_at and moves the version', function () {
    $response = archiveCall($this->credential, $this->category->id)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2);

    expect($response->json('data.archived_at'))->not->toBeNull();
});

test('archiving again is 200 and unchanged', function () {
    $first = archiveCall($this->credential, $this->category->id)->json('data');

    archiveCall($this->credential, $this->category->id)
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', $first['version'])
        ->assertJsonPath('data.archived_at', $first['archived_at'])
        ->assertJsonPath('data.change_seq', $first['change_seq']);
});

test('a stale version is 409 with the current category', function () {
    walletCall('PATCH', $this->credential, '/categories/'.$this->category->id, ['name' => 'New', 'version' => 1])->assertOk();

    archiveCall($this->credential, $this->category->id)
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.name', 'New');
});

test('a tombstone is 409', function () {
    $gone = ownedCategory($this->user->id, ['deleted_at' => now()]);

    archiveCall($this->credential, $gone->id)->assertValidResponse(409);
});

test("another person's category and a platform id are 404", function () {
    $hers = ownedCategory(person('grace@example.test')->id);
    $platform = Category::query()->whereNull('user_id')->firstOrFail();

    archiveCall($this->credential, $hers->id)->assertValidResponse(404);
    archiveCall($this->credential, $platform->id)->assertValidResponse(404);
});

test('a missing version is REQUIRED at /version', function () {
    $response = archiveCall($this->credential, $this->category->id, [])->assertStatus(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/version', 'code' => 'REQUIRED']);
});

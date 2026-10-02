<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Testing\TestResponse;

/*
 * DELETE /categories/{id} carries its version in a JSON body (spec 011, US3; spec 010 R-5).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->category = ownedCategory($this->user->id);
});

function deleteCategoryCall(string $credential, string $id, array $body = ['version' => 1]): TestResponse
{
    return walletCall('DELETE', $credential, '/categories/'.$id, $body);
}

test('deleting returns the tombstone and the row stays', function () {
    $response = deleteCategoryCall($this->credential, $this->category->id)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2);

    expect($response->json('data.deleted_at'))->not->toBeNull()
        ->and(Category::query()->find($this->category->id))->not->toBeNull();
});

test('deleting again is 200 with the same tombstone, whatever version is sent', function () {
    $first = deleteCategoryCall($this->credential, $this->category->id)->json('data');

    deleteCategoryCall($this->credential, $this->category->id, ['version' => 9])
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', $first['version'])
        ->assertJsonPath('data.deleted_at', $first['deleted_at']);
});

test('a used category is 409 CATEGORY_IN_USE and nothing changes', function () {
    fakeCategoryUsage([$this->category->id]);

    deleteCategoryCall($this->credential, $this->category->id)
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'CATEGORY_IN_USE');

    expect($this->category->fresh()->deleted_at)->toBeNull()->and($this->category->fresh()->version)->toBe(1);

    archiveCategoryOk($this);
});

function archiveCategoryOk(object $test): void
{
    walletCall('POST', $test->credential, '/categories/'.$test->category->id.'/archive', ['version' => 1])
        ->assertValidResponse(200)
        ->assertJsonPath('data.in_use', true);
}

test('a stale version is 409 VERSION_CONFLICT with the current category', function () {
    walletCall('PATCH', $this->credential, '/categories/'.$this->category->id, ['name' => 'New', 'version' => 1])->assertOk();

    deleteCategoryCall($this->credential, $this->category->id)
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.name', 'New');
});

test('a missing version or an empty body is 422 REQUIRED at /version', function () {
    $response = deleteCategoryCall($this->credential, $this->category->id, [])->assertStatus(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/version', 'code' => 'REQUIRED']);
});

test('no body and no content type is 422 REQUIRED, not 415', function () {
    $this->call('DELETE', '/api/v1/categories/'.$this->category->id, [], [], [], $this->transformHeadersToServerVars(asCredential($this->credential)))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.pointer', '/version')
        ->assertJsonPath('errors.0.code', 'REQUIRED');
});

test('a body that is not JSON is 415', function () {
    $this->call('DELETE', '/api/v1/categories/'.$this->category->id, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Content-Type' => 'text/plain']), 'version=1')
        ->assertStatus(415)
        ->assertJsonPath('code', 'UNSUPPORTED_MEDIA_TYPE');
});

test("another person's category and a platform id are 404", function () {
    $hers = ownedCategory(person('grace@example.test')->id);
    $platform = Category::query()->whereNull('user_id')->firstOrFail();

    deleteCategoryCall($this->credential, $hers->id)->assertValidResponse(404);
    deleteCategoryCall($this->credential, $platform->id)->assertValidResponse(404);

    expect($hers->fresh()->deleted_at)->toBeNull()->and($platform->fresh()->version)->toBe(1);
});

test('a deleted category disappears from the list', function () {
    deleteCategoryCall($this->credential, $this->category->id)->assertOk();

    walletCall('GET', $this->credential, '/categories')->assertValidResponse(200)->assertJsonPath('data', []);
});

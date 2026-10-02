<?php

use Illuminate\Testing\TestResponse;

/*
 * PATCH /categories/{id} (spec 011, US2).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->category = ownedCategory($this->user->id, ['name' => 'Coffee', 'icon' => 'cup', 'color' => '#6D4C41']);
});

function patchCategory(string $credential, string $id, array $body): TestResponse
{
    return walletCall('PATCH', $credential, '/categories/'.$id, $body);
}

test('a partial update leaves the absent members untouched', function () {
    patchCategory($this->credential, $this->category->id, ['name' => 'Tea', 'version' => 1])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.name', 'Tea')
        ->assertJsonPath('data.icon', 'cup')
        ->assertJsonPath('data.color', '#6D4C41')
        ->assertJsonPath('data.version', 2);
});

test('icon and colour can be cleared with null', function () {
    patchCategory($this->credential, $this->category->id, ['icon' => null, 'color' => null, 'version' => 1])
        ->assertValidResponse(200)
        ->assertJsonPath('data.icon', null)
        ->assertJsonPath('data.color', null);
});

test('an identical patch is 200 and changes nothing', function () {
    $before = $this->category->fresh();

    patchCategory($this->credential, $this->category->id, ['name' => 'Coffee', 'version' => 1])
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.change_seq', $before->change_seq);
});

test('a stale version is 409 with the current category', function () {
    patchCategory($this->credential, $this->category->id, ['name' => 'One', 'version' => 1])->assertOk();

    patchCategory($this->credential, $this->category->id, ['name' => 'Two', 'version' => 1])
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.name', 'One');
});

test('only the version is REQUIRED at the body root', function () {
    $response = patchCategory($this->credential, $this->category->id, ['version' => 1])->assertStatus(422);

    expect($response->json('code'))->toBe('VALIDATION_FAILED')
        ->and($response->json('errors.0'))->toMatchArray(['pointer' => '', 'code' => 'REQUIRED']);
});

test('a missing version is REQUIRED at /version', function () {
    $response = patchCategory($this->credential, $this->category->id, ['name' => 'X'])->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/version')['code'])->toBe('REQUIRED');
});

test('the kind is not editable here: UNKNOWN_FIELD', function () {
    $response = patchCategory($this->credential, $this->category->id, ['kind' => 'income', 'version' => 1])->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/kind')['code'])->toBe('UNKNOWN_FIELD');
});

test('a blank or long name is OUT_OF_RANGE', function (string $name) {
    $response = patchCategory($this->credential, $this->category->id, ['name' => $name, 'version' => 1])->assertValidResponse(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/name')['code'])->toBe('OUT_OF_RANGE');
})->with(['   ', '', 'long' => str_repeat('a', 51)]);

test('an unknown id is 404, a patch never creates', function () {
    patchCategory($this->credential, '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70', ['name' => 'X', 'version' => 1])
        ->assertValidResponse(404);
});

test('a tombstone is 409 VERSION_CONFLICT', function () {
    $gone = ownedCategory($this->user->id, ['deleted_at' => now()]);

    patchCategory($this->credential, $gone->id, ['name' => 'X', 'version' => 1])->assertValidResponse(409);
});

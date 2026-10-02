<?php

use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * PUT /categories/{id} (spec 011, US2).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->id = (string) Str::uuid7();
    $this->ownCount = Category::query()->whereNotNull('user_id')->count();
});

function putCategory(string $credential, string $id, array $body): TestResponse
{
    return walletCall('PUT', $credential, '/categories/'.$id, $body);
}

test('creating returns 201 with the full category and the caller as owner', function () {
    $response = putCategory($this->credential, $this->id, categoryBody())
        ->assertValidRequest()
        ->assertValidResponse(201);

    $response->assertHeader('X-Request-Id');
    expect($response->json('data'))->toMatchArray([
        'id' => $this->id,
        'user_id' => $this->user->id,
        'version' => 1,
        'kind' => 'expense',
        'name' => 'Coffee',
        'icon' => 'cup',
        'color' => '#6D4C41',
        'archived_at' => null,
        'deleted_at' => null,
        'in_use' => false,
    ])->and($response->json('data.change_seq'))->toBeInt()
        ->and($response->json('meta'))->toBe([]);
});

test('an identical replay is 200 with the same version and change_seq, with version 0 or 1', function (int $version) {
    $created = putCategory($this->credential, $this->id, categoryBody())->assertCreated()->json('data');

    putCategory($this->credential, $this->id, categoryBody(version: $version))
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.change_seq', $created['change_seq']);

    expect(Category::query()->whereNotNull('user_id')->count())->toBe($this->ownCount + 1);
})->with([0, 1]);

test('an update with the current version is 200 and moves the version', function () {
    putCategory($this->credential, $this->id, categoryBody())->assertCreated();

    putCategory($this->credential, $this->id, categoryBody(['name' => 'Tea', 'icon' => null, 'color' => null], version: 1))
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.name', 'Tea')
        ->assertJsonPath('data.icon', null);
});

test('the name is trimmed', function () {
    putCategory($this->credential, $this->id, categoryBody(['name' => '  Coffee  ']))
        ->assertValidResponse(201)
        ->assertJsonPath('data.name', 'Coffee');
});

test('a stale version is 409 VERSION_CONFLICT carrying the stored category', function () {
    putCategory($this->credential, $this->id, categoryBody())->assertCreated();
    putCategory($this->credential, $this->id, categoryBody(['name' => 'Tea'], version: 1))->assertOk();

    putCategory($this->credential, $this->id, categoryBody(['name' => 'Other'], version: 1))
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.name', 'Tea')
        ->assertJsonPath('current.version', 2);
});

test('an identical write to a tombstone is 409, never 200', function () {
    ownedCategory($this->user->id, ['id' => $this->id, 'name' => 'Coffee', 'icon' => 'cup', 'color' => '#6D4C41', 'deleted_at' => now()]);

    putCategory($this->credential, $this->id, categoryBody(version: 1))
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.deleted_at', fn ($at) => $at !== null);
});

test('the kind is immutable', function () {
    putCategory($this->credential, $this->id, categoryBody())->assertCreated();

    $response = putCategory($this->credential, $this->id, categoryBody(['kind' => 'income'], version: 1))->assertValidResponse(422);

    expect($response->json('code'))->toBe('VALIDATION_FAILED')
        ->and($response->json('errors.0'))->toMatchArray(['pointer' => '/kind', 'code' => 'IMMUTABLE_FIELD'])
        ->and(Category::query()->findOrFail($this->id)->kind)->toBe('expense');
});

test('another persons category, a platform category and a wallet are 404 and untouched', function () {
    $other = person('grace@example.test');
    $hers = ownedCategory($other->id, ['name' => 'Hers']);
    $platform = Category::query()->whereNull('user_id')->firstOrFail();
    $wallet = ownedWallet($this->user->id);

    foreach ([$hers->id, $platform->id, $wallet->id] as $held) {
        putCategory($this->credential, $held, categoryBody(['name' => 'Mine']))->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
        putCategory($this->credential, $held, categoryBody(['name' => 'Mine'], version: 1))->assertValidResponse(404);
    }

    expect(Category::query()->find($hers->id)->name)->toBe('Hers')
        ->and(Category::query()->find($platform->id)->names['en'])->toBe('Groceries');
});

test('a version above 0 for an unknown id is 404 and creates nothing', function () {
    putCategory($this->credential, $this->id, categoryBody(version: 2))->assertValidResponse(404);

    expect(Category::query()->whereNotNull('user_id')->count())->toBe($this->ownCount);
});

test('an id that is not a UUIDv7 is 422 INVALID_ID', function () {
    putCategory($this->credential, (string) Str::uuid(), categoryBody())
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'INVALID_ID');
    putCategory($this->credential, 'not-an-id', categoryBody())
        ->assertJsonPath('code', 'INVALID_ID');
});

test('invalid bodies are refused with their field codes', function (array $changes, string $pointer, string $code) {
    $response = putCategory($this->credential, $this->id, [...categoryBody(), ...$changes]);

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    if ($code !== 'UNKNOWN_FIELD') {
        $response->assertValidResponse(422);
    }
    expect(collect($response->json('errors'))->firstWhere('pointer', $pointer)['code'] ?? null)->toBe($code)
        ->and(Category::query()->whereNotNull('user_id')->count())->toBe($this->ownCount);
})->with([
    'blank name' => [['name' => '   '], '/name', 'OUT_OF_RANGE'],
    'empty name' => [['name' => ''], '/name', 'OUT_OF_RANGE'],
    'long name' => [['name' => str_repeat('a', 51)], '/name', 'OUT_OF_RANGE'],
    'unknown kind' => [['kind' => 'transfer'], '/kind', 'INVALID_ENUM_VALUE'],
    'bad colour' => [['color' => '#6d4c41'], '/color', 'INVALID_TYPE'],
    'bad icon' => [['icon' => 'Cup Icon'], '/icon', 'INVALID_TYPE'],
    'user_id' => [['user_id' => '0192f79e-0000-7000-8000-000000000001'], '/user_id', 'UNKNOWN_FIELD'],
    'system_key' => [['system_key' => 'groceries'], '/system_key', 'UNKNOWN_FIELD'],
    'archived_at' => [['archived_at' => null], '/archived_at', 'UNKNOWN_FIELD'],
    'in_use' => [['in_use' => false], '/in_use', 'UNKNOWN_FIELD'],
    'parent_id' => [['parent_id' => null], '/parent_id', 'UNKNOWN_FIELD'],
    'change_seq' => [['change_seq' => 5], '/change_seq', 'UNKNOWN_FIELD'],
    'deleted_at' => [['deleted_at' => null], '/deleted_at', 'UNKNOWN_FIELD'],
]);

test('a missing member is REQUIRED', function () {
    $body = categoryBody();
    unset($body['name']);

    $response = putCategory($this->credential, $this->id, $body)->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/name')['code'])->toBe('REQUIRED');
});

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, upgrade required', function () {
    $this->withHeaders(jsonHeaders())->putJson('/api/v1/categories/'.$this->id, categoryBody())
        ->assertValidResponse(401);

    $this->call('PUT', '/api/v1/categories/'.$this->id, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Accept' => 'text/html']), '{}')
        ->assertStatus(406);

    $this->call('PUT', '/api/v1/categories/'.$this->id, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Content-Type' => 'text/plain']), 'name=x')
        ->assertStatus(415);

    $this->withHeaders([...asCredential($this->credential), 'X-Client-Version' => '0.0.1'])->putJson('/api/v1/categories/'.$this->id, categoryBody())
        ->assertStatus(426);
});

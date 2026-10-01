<?php

use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * PUT /wallets/{id} (spec 010, US1 and US2).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->id = (string) Str::uuid7();
});

function putWallet(string $credential, string $id, array $body): TestResponse
{
    return walletCall('PUT', $credential, '/wallets/'.$id, $body);
}

test('creating returns 201 with the full wallet', function () {
    $response = putWallet($this->credential, $this->id, walletBody())
        ->assertValidRequest()
        ->assertValidResponse(201);

    $response->assertHeader('X-Request-Id');
    expect($response->json('data'))->toMatchArray([
        'id' => $this->id,
        'wallet_id' => $this->id,
        'version' => 1,
        'change_seq' => 3,
        'type' => 'cash',
        'name' => 'Cash',
        'currency' => 'EGP',
        'opening_balance_minor' => 50000,
        'balance_minor' => 50000,
        'icon' => 'wallet',
        'color' => '#2E7D32',
        'archived_at' => null,
        'deleted_at' => null,
        'currency_locked' => false,
        'my_role' => 'owner',
    ])->and($response->json('meta'))->toBe([]);
});

test('an identical replay is 200 with the same version and change_seq', function () {
    putWallet($this->credential, $this->id, walletBody())->assertCreated();

    putWallet($this->credential, $this->id, walletBody())
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.change_seq', 3);

    expect(Wallet::query()->count())->toBe(1);
});

test('a liability may open negative', function () {
    putWallet($this->credential, $this->id, walletBody(['type' => 'credit_card', 'opening_balance_minor' => -250000]))
        ->assertValidResponse(201)
        ->assertJsonPath('data.balance_minor', -250000);
});

test('an update with the current version is 200 and moves the version', function () {
    putWallet($this->credential, $this->id, walletBody())->assertCreated();

    putWallet($this->credential, $this->id, walletBody(['name' => 'Spending', 'opening_balance_minor' => 60000], version: 1))
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.name', 'Spending')
        ->assertJsonPath('data.balance_minor', 60000);
});

test('a stale version is 409 VERSION_CONFLICT carrying the current wallet', function () {
    putWallet($this->credential, $this->id, walletBody())->assertCreated();
    putWallet($this->credential, $this->id, walletBody(['name' => 'Spending'], version: 1))->assertOk();

    putWallet($this->credential, $this->id, walletBody(['name' => 'Other'], version: 1))
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.version', 2)
        ->assertJsonPath('current.name', 'Spending');
});

test('a deleted wallet answers 409 with the tombstone, even to an identical write', function () {
    putWallet($this->credential, $this->id, walletBody())->assertCreated();
    walletCall('DELETE', $this->credential, '/wallets/'.$this->id, ['version' => 1])->assertOk();

    putWallet($this->credential, $this->id, walletBody())
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.deleted_at', fn ($at) => $at !== null);
});

test('another person\'s wallet id is 404 and the wallet is untouched', function () {
    $other = person('grace@example.test');
    $wallet = ownedWallet($other->id, ['name' => 'Hers']);

    putWallet($this->credential, $wallet->id, walletBody(['name' => 'Mine']))
        ->assertValidResponse(404)
        ->assertJsonPath('code', 'NOT_FOUND');
    putWallet($this->credential, $wallet->id, walletBody(['name' => 'Mine'], version: 1))
        ->assertValidResponse(404);

    expect(Wallet::query()->find($wallet->id)->name)->toBe('Hers');
});

test('a version above 0 for an unknown id is 404', function () {
    putWallet($this->credential, $this->id, walletBody(version: 2))
        ->assertValidResponse(404);

    expect(Wallet::query()->count())->toBe(0);
});

test('an id that is not a UUIDv7 is 422 INVALID_ID', function () {
    putWallet($this->credential, (string) Str::uuid(), walletBody())
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'INVALID_ID');
    putWallet($this->credential, 'not-an-id', walletBody())
        ->assertJsonPath('code', 'INVALID_ID');
});

test('an editor is 403 FORBIDDEN and a non-member 404', function () {
    $owner = person('grace@example.test');
    $wallet = ownedWallet($owner->id);
    addMember($wallet->id, $this->user->id, 'editor');

    putWallet($this->credential, $wallet->id, walletBody(['name' => 'X'], version: 1))
        ->assertValidResponse(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

test('the type is immutable and the currency is immutable once locked', function () {
    putWallet($this->credential, $this->id, walletBody())->assertCreated();

    $response = putWallet($this->credential, $this->id, walletBody(['type' => 'savings'], version: 1))->assertValidResponse(422);
    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/type', 'code' => 'IMMUTABLE_FIELD']);

    fakePostings(hasAny: true);
    $locked = putWallet($this->credential, $this->id, walletBody(['currency' => 'USD'], version: 1))->assertValidResponse(422);
    expect($locked->json('errors.0'))->toMatchArray(['pointer' => '/currency', 'code' => 'IMMUTABLE_FIELD']);
});

test('invalid bodies are refused with their field codes', function (array $changes, string $pointer, string $code) {
    $response = putWallet($this->credential, $this->id, [...walletBody(), ...$changes]);

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    if ($code !== 'UNKNOWN_FIELD') {
        $response->assertValidResponse(422);
    }
    expect(collect($response->json('errors'))->firstWhere('pointer', $pointer)['code'] ?? null)->toBe($code)
        ->and(Wallet::query()->count())->toBe(0);
})->with([
    'blank name' => [['name' => '   '], '/name', 'OUT_OF_RANGE'],
    'empty name' => [['name' => ''], '/name', 'OUT_OF_RANGE'],
    'long name' => [['name' => str_repeat('a', 101)], '/name', 'OUT_OF_RANGE'],
    'unknown type' => [['type' => 'bank'], '/type', 'INVALID_ENUM_VALUE'],
    'decimal string amount' => [['opening_balance_minor' => '125.00'], '/opening_balance_minor', 'AMOUNT_NOT_INTEGER'],
    'float amount' => [['opening_balance_minor' => 12.5], '/opening_balance_minor', 'AMOUNT_NOT_INTEGER'],
    'lower-case currency' => [['currency' => 'egp'], '/currency', 'INVALID_CURRENCY'],
    'bad colour' => [['color' => '#2e7d32'], '/color', 'INVALID_TYPE'],
    'bad icon' => [['icon' => 'Wallet Icon'], '/icon', 'INVALID_TYPE'],
    'server-owned balance' => [['balance_minor' => 5], '/balance_minor', 'UNKNOWN_FIELD'],
    'server-owned change_seq' => [['change_seq' => 5], '/change_seq', 'UNKNOWN_FIELD'],
    'server-owned deleted_at' => [['deleted_at' => null], '/deleted_at', 'UNKNOWN_FIELD'],
]);

test('a missing member is REQUIRED', function () {
    $body = walletBody();
    unset($body['name']);

    $response = putWallet($this->credential, $this->id, $body)->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/name')['code'])->toBe('REQUIRED');
});

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, upgrade required', function () {
    $this->withHeaders(jsonHeaders())->putJson('/api/v1/wallets/'.$this->id, walletBody())
        ->assertValidResponse(401);

    $this->call('PUT', '/api/v1/wallets/'.$this->id, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Accept' => 'text/html']), '{}')
        ->assertStatus(406);

    $this->call('PUT', '/api/v1/wallets/'.$this->id, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Content-Type' => 'text/plain']), 'name=x')
        ->assertStatus(415);

    $this->withHeaders([...asCredential($this->credential), 'X-Client-Version' => '0.0.1'])->putJson('/api/v1/wallets/'.$this->id, walletBody())
        ->assertStatus(426);
});

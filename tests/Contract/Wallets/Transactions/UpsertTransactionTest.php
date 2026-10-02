<?php

use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * PUT /wallets/{id}/transactions/{tid} (spec 012, US1, US3; every documented
 * status of the operation).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    $this->id = (string) Str::uuid7();
});

function putTransaction(string $credential, string $walletId, string $id, array $body): TestResponse
{
    return transactionCall('PUT', $credential, $walletId, $id, $body);
}

test('creating returns 201 with the full transaction and moves the balance', function () {
    $response = putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody())
        ->assertValidRequest()
        ->assertValidResponse(201);

    $response->assertHeader('X-Request-Id');
    expect($response->json('data'))->toMatchArray([
        'id' => $this->id,
        'wallet_id' => $this->wallet->id,
        'version' => 1,
        'kind' => 'expense',
        'date' => '2026-10-01',
        'amount_minor' => 2500,
        'currency' => 'EGP',
        'counter_wallet_id' => null,
        'description' => 'Lunch',
        'memo' => null,
        'counterparty' => null,
        'cleared' => false,
        'created_by' => $this->user->id,
        'deleted_at' => null,
    ])->and($response->json('meta'))->toBe([])
        ->and(walletBalance($this->wallet->id))->toBe(47500);
});

test('an identical replay is 200 with the same version and change_seq', function () {
    $first = putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody())->assertCreated();

    putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody())
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.change_seq', $first->json('data.change_seq'));

    expect(Transaction::query()->count())->toBe(1)->and(walletBalance($this->wallet->id))->toBe(47500);
});

test('a save with the current version is 200 and moves the version', function () {
    putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody())->assertCreated();

    putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody(['amount_minor' => 4000, 'cleared' => true], version: 1))
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.amount_minor', 4000)
        ->assertJsonPath('data.cleared', true);

    expect(walletBalance($this->wallet->id))->toBe(46000);
});

test('an income is 201 and raises the balance', function () {
    putTransaction($this->credential, $this->wallet->id, $this->id, incomeBody(['amount_minor' => 1000]))
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('data.kind', 'income');

    expect(walletBalance($this->wallet->id))->toBe(51000);
});

test('a stale version is 409 VERSION_CONFLICT carrying the current transaction', function () {
    putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody())->assertCreated();
    putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody(['amount_minor' => 3000], version: 1))->assertOk();

    putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody(['amount_minor' => 9000], version: 1))
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.version', 2)
        ->assertJsonPath('current.amount_minor', 3000);
});

test('a tombstone answers 409 with itself, even to an identical write', function () {
    putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody())->assertCreated();
    transactionCall('DELETE', $this->credential, $this->wallet->id, $this->id, ['version' => 1])->assertOk();

    putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody())
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.deleted_at', fn ($at) => $at !== null);
});

test('404: another wallet\'s transaction, a wallet, a category, a version for an unknown id, and a non-member', function () {
    $stranger = person('grace@example.test');
    $theirs = ownedWallet($stranger->id);
    $foreign = recordTransaction($stranger->id, $theirs->id)->transaction->id;
    $category = ownedCategory($this->user->id);

    putTransaction($this->credential, $this->wallet->id, $foreign, transactionBody())->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
    putTransaction($this->credential, $this->wallet->id, $this->wallet->id, transactionBody())->assertValidResponse(404);
    putTransaction($this->credential, $this->wallet->id, $category->id, transactionBody())->assertValidResponse(404);
    putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody(version: 2))->assertValidResponse(404);
    putTransaction($this->credential, $theirs->id, $this->id, transactionBody())->assertValidResponse(404);

    expect(Transaction::query()->count())->toBe(1);
});

test('a viewer is 403 FORBIDDEN', function () {
    $owner = person('grace@example.test');
    $wallet = ownedWallet($owner->id);
    addMember($wallet->id, $this->user->id, 'viewer');

    putTransaction($this->credential, $wallet->id, $this->id, transactionBody())
        ->assertValidResponse(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

test('an id that is not a UUIDv7 is 422 INVALID_ID', function () {
    putTransaction($this->credential, $this->wallet->id, (string) Str::uuid(), transactionBody())
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'INVALID_ID');
    putTransaction($this->credential, $this->wallet->id, 'not-an-id', transactionBody())->assertJsonPath('code', 'INVALID_ID');
});

test('state rules: currency, category, immutables, required members, one entry each', function (array $changes, int $version, string $pointer, string $code) {
    putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody())->assertCreated();

    $response = putTransaction($this->credential, $this->wallet->id, $this->id, transactionBody($changes, $version))
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    expect(collect($response->json('errors'))->firstWhere('pointer', $pointer)['code'] ?? null)->toBe($code);
})->with([
    'currency not the wallet\'s' => [['currency' => 'USD'], 1, '/currency', 'CURRENCY_MISMATCH'],
    'kind change' => [['kind' => 'income', 'category_id' => null], 1, '/kind', 'IMMUTABLE_FIELD'],
    'unknown category' => [['category_id' => '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70'], 1, '/category_id', 'INVALID_ENUM_VALUE'],
    'missing category' => [['category_id' => null], 1, '/category_id', 'REQUIRED'],
    'counter wallet on an expense' => [['counter_wallet_id' => '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70'], 1, '/counter_wallet_id', 'INVALID_ENUM_VALUE'],
]);

test('invalid bodies are refused with their field codes', function (array $changes, string $pointer, string $code) {
    $response = putTransaction($this->credential, $this->wallet->id, $this->id, [...transactionBody(), ...$changes]);

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    if ($code !== 'UNKNOWN_FIELD') {
        $response->assertValidResponse(422);
    }
    expect(collect($response->json('errors'))->firstWhere('pointer', $pointer)['code'] ?? null)->toBe($code)
        ->and(Transaction::query()->count())->toBe(0);
})->with([
    'decimal string amount' => [['amount_minor' => '25.00'], '/amount_minor', 'AMOUNT_NOT_INTEGER'],
    'float amount' => [['amount_minor' => 25.5], '/amount_minor', 'AMOUNT_NOT_INTEGER'],
    'zero amount' => [['amount_minor' => 0], '/amount_minor', 'OUT_OF_RANGE'],
    'negative amount' => [['amount_minor' => -1], '/amount_minor', 'OUT_OF_RANGE'],
    'amount above 2^53 - 1' => [['amount_minor' => 9007199254740992], '/amount_minor', 'OUT_OF_RANGE'],
    'lower-case currency' => [['currency' => 'egp'], '/currency', 'INVALID_CURRENCY'],
    'date with a time' => [['date' => '2026-10-01T10:00:00Z'], '/date', 'DATE_NOT_CALENDAR_DATE'],
    'impossible date' => [['date' => '2026-02-30'], '/date', 'DATE_NOT_CALENDAR_DATE'],
    'date before 1900' => [['date' => '1899-12-31'], '/date', 'OUT_OF_RANGE'],
    'date after 2999' => [['date' => '3000-01-01'], '/date', 'OUT_OF_RANGE'],
    'unknown kind' => [['kind' => 'refund'], '/kind', 'INVALID_ENUM_VALUE'],
    'long description' => [['description' => str_repeat('a', 101)], '/description', 'OUT_OF_RANGE'],
    'long memo' => [['memo' => str_repeat('a', 1001)], '/memo', 'OUT_OF_RANGE'],
    'long counterparty' => [['counterparty' => str_repeat('a', 101)], '/counterparty', 'OUT_OF_RANGE'],
    'cleared as a string' => [['cleared' => 'yes'], '/cleared', 'INVALID_TYPE'],
    'category id not a uuid' => [['category_id' => 'coffee'], '/category_id', 'INVALID_ID'],
    'server-owned postings' => [['postings' => []], '/postings', 'UNKNOWN_FIELD'],
    'server-owned wallet_id' => [['wallet_id' => '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70'], '/wallet_id', 'UNKNOWN_FIELD'],
    'server-owned created_by' => [['created_by' => '0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70'], '/created_by', 'UNKNOWN_FIELD'],
    'server-owned change_seq' => [['change_seq' => 5], '/change_seq', 'UNKNOWN_FIELD'],
]);

test('a missing member is REQUIRED', function (string $member) {
    $body = transactionBody();
    unset($body[$member]);

    $response = putTransaction($this->credential, $this->wallet->id, $this->id, $body)->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/'.$member)['code'])->toBe('REQUIRED');
})->with(['kind', 'date', 'amount_minor', 'currency', 'category_id', 'counter_wallet_id', 'description', 'memo', 'counterparty', 'cleared']);

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, upgrade required', function () {
    $this->withHeaders(jsonHeaders())->putJson('/api/v1/wallets/'.$this->wallet->id.'/transactions/'.$this->id, transactionBody())
        ->assertValidResponse(401);

    $this->call('PUT', '/api/v1/wallets/'.$this->wallet->id.'/transactions/'.$this->id, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Accept' => 'text/html']), '{}')
        ->assertStatus(406);

    $this->call('PUT', '/api/v1/wallets/'.$this->wallet->id.'/transactions/'.$this->id, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Content-Type' => 'text/plain']), 'x=1')
        ->assertStatus(415);

    $this->withHeaders([...asCredential($this->credential), 'X-Client-Version' => '0.0.1'])->putJson('/api/v1/wallets/'.$this->wallet->id.'/transactions/'.$this->id, transactionBody())
        ->assertStatus(426);
});

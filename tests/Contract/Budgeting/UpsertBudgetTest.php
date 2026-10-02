<?php

use App\Modules\Budgeting\Infrastructure\Models\Budget;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * PUT /budgets/{id} (spec 015, US1; FR-001 to FR-006): create, replay, edit,
 * every documented status.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->id = (string) Str::uuid7();
});

function putBudget(string $credential, string $id, array $body): TestResponse
{
    return budgetCall('PUT', $credential, '/budgets/'.$id, $body);
}

test('creating returns 201 with the full budget and the caller as owner', function () {
    $response = putBudget($this->credential, $this->id, budgetBody())
        ->assertValidRequest()
        ->assertValidResponse(201);

    $response->assertHeader('X-Request-Id');
    expect($response->json('data'))->toMatchArray([
        'id' => $this->id,
        'user_id' => $this->user->id,
        'version' => 1,
        'period_type' => 'monthly',
        'amount_minor' => 600000,
        'currency' => 'EGP',
        'archived_at' => null,
        'deleted_at' => null,
    ])->and($response->json('data.change_seq'))->toBeInt()
        ->and(array_keys($response->json('data')))->toBe(['id', 'user_id', 'version', 'change_seq', 'created_at', 'updated_at', 'deleted_at', 'category_id', 'period_type', 'amount_minor', 'currency', 'archived_at'])
        ->and($response->json('meta'))->toBe([]);
});

test('an all-expenses budget is created with a null category', function () {
    putBudget($this->credential, $this->id, budgetBody(['category_id' => null]))
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('data.category_id', null);
});

test('an identical replay is 200 with the same version and change_seq, with version 0 or 1', function (int $version) {
    $created = putBudget($this->credential, $this->id, budgetBody())->assertCreated()->json('data');

    putBudget($this->credential, $this->id, budgetBody(version: $version))
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.change_seq', $created['change_seq']);

    expect(Budget::query()->count())->toBe(1);
})->with([0, 1]);

test('an omitted version creates', function () {
    $body = budgetBody();
    unset($body['version']);

    putBudget($this->credential, $this->id, $body)->assertValidRequest()->assertValidResponse(201);
});

test('an update with the current version is 200 and moves the version', function () {
    putBudget($this->credential, $this->id, budgetBody())->assertCreated();

    putBudget($this->credential, $this->id, budgetBody(['amount_minor' => 700000], version: 1))
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.amount_minor', 700000);
});

test('a stale version is 409 VERSION_CONFLICT carrying the stored budget', function () {
    putBudget($this->credential, $this->id, budgetBody())->assertCreated();
    putBudget($this->credential, $this->id, budgetBody(['amount_minor' => 700000], version: 1))->assertOk();

    putBudget($this->credential, $this->id, budgetBody(['amount_minor' => 900000], version: 1))
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.amount_minor', 700000)
        ->assertJsonPath('current.version', 2);
});

test('an identical write to a tombstone is 409, never 200', function () {
    $category = platformCategoryId();
    ownedBudget($this->user->id, ['id' => $this->id, 'category_id' => $category, 'deleted_at' => now()]);

    putBudget($this->credential, $this->id, budgetBody(['category_id' => $category, 'amount_minor' => 500000], version: 1))
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.deleted_at', fn ($at) => $at !== null);
});

test('the category, currency and period type are immutable, each at its pointer', function (array $changes, string $pointer) {
    putBudget($this->credential, $this->id, budgetBody())->assertCreated();

    $response = putBudget($this->credential, $this->id, budgetBody($changes, version: 1))->assertValidResponse(422);

    expect($response->json('code'))->toBe('VALIDATION_FAILED')
        ->and($response->json('errors.0'))->toMatchArray(['pointer' => $pointer, 'code' => 'IMMUTABLE_FIELD']);
})->with([
    'category' => [['category_id' => null], '/category_id'],
    'currency' => [['currency' => 'USD'], '/currency'],
]);

test('another person\'s budget, a wallet and a category are 404 and untouched', function () {
    $other = person('grace@example.test');
    $hers = ownedBudget($other->id);
    $wallet = ownedWallet($this->user->id);
    $category = ownedCategory($this->user->id);

    foreach ([$hers->id, $wallet->id, $category->id] as $held) {
        putBudget($this->credential, $held, budgetBody())->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
        putBudget($this->credential, $held, budgetBody(version: 1))->assertValidResponse(404);
    }

    expect(Budget::query()->count())->toBe(1)->and(Budget::query()->find($hers->id)->user_id)->toBe($other->id);
});

test('a version above 0 for an unknown id is 404 and creates nothing', function () {
    putBudget($this->credential, $this->id, budgetBody(version: 2))->assertValidResponse(404);

    expect(Budget::query()->count())->toBe(0);
});

test('an id that is not a UUIDv7 is 422 INVALID_ID', function () {
    putBudget($this->credential, (string) Str::uuid(), budgetBody())->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
    putBudget($this->credential, 'not-an-id', budgetBody())->assertJsonPath('code', 'INVALID_ID');
});

test('a category that is not an eligible expense category is 422 INVALID_ENUM_VALUE at /category_id', function (Closure $category) {
    $response = putBudget($this->credential, $this->id, budgetBody(['category_id' => $category($this)]))->assertValidResponse(422);

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/category_id', 'code' => 'INVALID_ENUM_VALUE'])
        ->and(Budget::query()->count())->toBe(0);
})->with([
    'income' => [fn () => platformCategoryId('income')],
    'unknown' => [fn () => (string) Str::uuid7()],
    'someone else\'s' => [fn () => ownedCategory(person('grace@example.test')->id)->id],
]);

test('invalid bodies are refused with their field codes', function (array $changes, string $pointer, string $code) {
    $response = putBudget($this->credential, $this->id, [...budgetBody(), ...$changes]);

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    if ($code !== 'UNKNOWN_FIELD') {
        $response->assertValidResponse(422);
    }
    expect(collect($response->json('errors'))->firstWhere('pointer', $pointer)['code'] ?? null)->toBe($code)
        ->and(Budget::query()->count())->toBe(0);
})->with([
    'zero amount' => [['amount_minor' => 0], '/amount_minor', 'OUT_OF_RANGE'],
    'negative amount' => [['amount_minor' => -5], '/amount_minor', 'OUT_OF_RANGE'],
    'amount 2^53' => [['amount_minor' => 9007199254740992], '/amount_minor', 'OUT_OF_RANGE'],
    'decimal amount' => [['amount_minor' => 10.5], '/amount_minor', 'AMOUNT_NOT_INTEGER'],
    'string amount' => [['amount_minor' => '100'], '/amount_minor', 'AMOUNT_NOT_INTEGER'],
    'lower-case currency' => [['currency' => 'egp'], '/currency', 'INVALID_CURRENCY'],
    'unknown currency' => [['currency' => 'ZZZ'], '/currency', 'INVALID_CURRENCY'],
    'weekly' => [['period_type' => 'weekly'], '/period_type', 'INVALID_ENUM_VALUE'],
    'category not a uuid' => [['category_id' => 'groceries'], '/category_id', 'INVALID_ID'],
    'category of the wrong type' => [['category_id' => 5], '/category_id', 'INVALID_TYPE'],
    'rollover' => [['rollover' => true], '/rollover', 'UNKNOWN_FIELD'],
    'user_id' => [['user_id' => '0192f79e-0000-7000-8000-000000000001'], '/user_id', 'UNKNOWN_FIELD'],
    'archived_at' => [['archived_at' => null], '/archived_at', 'UNKNOWN_FIELD'],
    'change_seq' => [['change_seq' => 5], '/change_seq', 'UNKNOWN_FIELD'],
]);

test('a missing member is REQUIRED', function (string $member) {
    $body = budgetBody();
    unset($body[$member]);

    $response = putBudget($this->credential, $this->id, $body)->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/'.$member)['code'])->toBe('REQUIRED');
})->with(['category_id', 'period_type', 'amount_minor', 'currency']);

test('the shared refusals: unauthenticated, not acceptable, unsupported media type, upgrade required', function () {
    $this->withHeaders(jsonHeaders())->putJson('/api/v1/budgets/'.$this->id, budgetBody())
        ->assertValidResponse(401);

    $this->call('PUT', '/api/v1/budgets/'.$this->id, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Accept' => 'text/html']), '{}')
        ->assertStatus(406);

    $this->call('PUT', '/api/v1/budgets/'.$this->id, [], [], [], $this->transformHeadersToServerVars([...asCredential($this->credential), 'Content-Type' => 'text/plain']), 'x=1')
        ->assertStatus(415);

    $this->withHeaders([...asCredential($this->credential), 'X-Client-Version' => '0.0.1'])->putJson('/api/v1/budgets/'.$this->id, budgetBody())
        ->assertStatus(426);
});

<?php

use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * PUT /wallets/{id}/recurring-rules/{rid} (spec 014, US1; every documented
 * status of the operation).
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->id = (string) Str::uuid7();
});

function putRule(string $credential, string $walletId, string $id, array $body): TestResponse
{
    return recurringRuleCall('PUT', $credential, $walletId, $id, $body);
}

test('creating returns 201 with the full rule, its timezone and author, and materialises', function () {
    $response = putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody())
        ->assertValidRequest()
        ->assertValidResponse(201);

    $response->assertHeader('X-Request-Id');
    expect($response->json('data'))->toMatchArray([
        'id' => $this->id,
        'wallet_id' => $this->wallet->id,
        'version' => 1,
        'preset' => 'custom',
        'status' => 'active',
        'kind' => 'expense',
        'amount_minor' => 450000,
        'currency' => 'EGP',
        'auto_confirm' => false,
        'frequency' => 'monthly',
        'anchor_day' => 1,
        'start_date' => '2026-11-01',
        'timezone' => 'Africa/Cairo',
        'created_by' => $this->user->id,
        'deleted_at' => null,
    ])->and($response->json('data'))->not->toHaveKey('auto_confirm_from')
        ->and($response->json('meta'))->toBe([])
        ->and(occurrencesOf($this->id))->toHaveCount(1);
});

test('an identical replay is 200 with the same version and change_seq', function () {
    $first = putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody())->assertCreated();

    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody())
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.change_seq', $first->json('data.change_seq'));

    expect(RecurringRule::query()->count())->toBe(1);
});

test('a save with the current version is 200 and moves the version', function () {
    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody())->assertCreated();

    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody(['amount_minor' => 500000, 'description' => 'Rent 2027'], 1))
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.amount_minor', 500000);
});

test('the createInstallment example splits the total exactly', function () {
    $body = recurringRuleBody([
        'preset' => 'installment', 'amount_basis' => 'total', 'amount_minor' => 1000000, 'description' => 'Fridge', 'counterparty' => 'B.TECH',
        'auto_confirm' => true, 'anchor_day' => 25, 'start_date' => '2026-10-25', 'occurrence_count' => 12,
    ]);

    putRule($this->credential, $this->wallet->id, $this->id, $body)->assertValidRequest()->assertValidResponse(201);
    $amounts = occurrencesOf($this->id)->pluck('amount_minor')->all();

    expect($amounts)->toBe([83334]);
});

test('every field refusal is a 422 VALIDATION_FAILED with its own code', function (string $member, mixed $value, string $code) {
    $response = putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody([$member => $value]))
        ->assertValidResponse(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    expect($response->json('errors.0'))->toMatchArray(['pointer' => '/'.$member, 'code' => $code]);
    expect(RecurringRule::query()->count())->toBe(0);
})->with([
    'fractional amount' => ['amount_minor', 12.5, 'AMOUNT_NOT_INTEGER'],
    'zero amount' => ['amount_minor', 0, 'OUT_OF_RANGE'],
    'interval too big' => ['interval', 366, 'OUT_OF_RANGE'],
    'unknown frequency' => ['frequency', 'hourly', 'INVALID_ENUM_VALUE'],
    'bad currency' => ['currency', 'euro', 'INVALID_CURRENCY'],
    'not a date' => ['start_date', '2026-02-30', 'DATE_NOT_CALENDAR_DATE'],
    'date out of range' => ['start_date', '1899-12-31', 'OUT_OF_RANGE'],
    'category not an id' => ['category_id', 'coffee', 'INVALID_ID'],
    'auto_confirm not a boolean' => ['auto_confirm', 'yes', 'INVALID_TYPE'],
]);

test('a state-rule refusal names its member: a wrong currency, a wrong-kind category, a missing anchor, a missing required member', function () {
    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody(['currency' => 'USD']))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/currency')
        ->assertJsonPath('errors.0.code', 'CURRENCY_MISMATCH');

    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody(['category_id' => platformCategoryId('income')]))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/category_id')
        ->assertJsonPath('errors.0.code', 'INVALID_ENUM_VALUE');

    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody(['anchor_day' => null]))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.code', 'REQUIRED');

    $body = recurringRuleBody();
    unset($body['memo']);
    putRule($this->credential, $this->wallet->id, $this->id, $body)->assertValidResponse(422)->assertJsonPath('errors.0.pointer', '/memo')->assertJsonPath('errors.0.code', 'REQUIRED');
});

test('server-owned members are UNKNOWN_FIELD', function (string $member) {
    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody([$member => 'x']))
        ->assertValidResponse(422)
        ->assertJsonPath('errors.0.pointer', '/'.$member)
        ->assertJsonPath('errors.0.code', 'UNKNOWN_FIELD');
})->with(['timezone', 'created_by', 'change_seq']);

test('a malformed id is 422 INVALID_ID before the body is judged', function () {
    putRule($this->credential, $this->wallet->id, 'not-an-id', [])->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
    putRule($this->credential, 'not-an-id', $this->id, recurringRuleBody())->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('a viewer is 403', function () {
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $viewer->id, 'viewer');
    $credential = signInAs('viewer@example.test')->json('data.credential');

    putRule($credential, $this->wallet->id, $this->id, recurringRuleBody())->assertValidResponse(403)->assertJsonPath('code', 'FORBIDDEN');
});

test('a non-member, a deleted wallet and a version above 0 for an unknown id are 404', function () {
    $stranger = ownedWallet(person('other@example.test')->id);
    putRule($this->credential, $stranger->id, $this->id, recurringRuleBody())->assertValidResponse(404);

    $deleted = ownedWallet($this->user->id, ['deleted_at' => now()]);
    putRule($this->credential, $deleted->id, $this->id, recurringRuleBody())->assertValidResponse(404);

    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody([], 3))->assertValidResponse(404);
});

test('an id held by a transaction, a wallet or a category is 404', function () {
    $transaction = recordTransaction($this->user->id, $this->wallet->id)->transaction;
    $category = ownedCategory($this->user->id);

    foreach ([$transaction->id, $this->wallet->id, $category->id] as $held) {
        putRule($this->credential, $this->wallet->id, $held, recurringRuleBody())->assertValidResponse(404);
    }

    expect(RecurringRule::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(1);
});

test('a stale version is 409 with the stored rule in current, and a tombstone is 409 too', function () {
    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody())->assertCreated();
    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody(['amount_minor' => 500000], 1))->assertOk();

    $stale = putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody(['amount_minor' => 600000], 1))
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.amount_minor', 500000);

    expect($stale->json('current.version'))->toBe(2);

    recurringRuleCall('DELETE', $this->credential, $this->wallet->id, $this->id, ['version' => 2])->assertOk();

    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody(['amount_minor' => 500000], 3))
        ->assertValidResponse(409)
        ->assertJsonPath('current.deleted_at', fn ($value) => $value !== null);
    putRule($this->credential, $this->wallet->id, $this->id, recurringRuleBody(['amount_minor' => 500000], 1))->assertValidResponse(409);
});

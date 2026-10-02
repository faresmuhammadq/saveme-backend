<?php

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * PATCH /budgets/{id} (spec 015, FR-006): the limit only, never creates.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->budget = ownedBudget($this->user->id, ['amount_minor' => 600000]);
});

function patchBudgetCall(string $credential, string $id, array $body): TestResponse
{
    return budgetCall('PATCH', $credential, '/budgets/'.$id, $body);
}

test('a patch changes the limit and nothing else', function () {
    patchBudgetCall($this->credential, $this->budget->id, ['amount_minor' => 700000, 'version' => 1])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.amount_minor', 700000)
        ->assertJsonPath('data.currency', 'EGP')
        ->assertJsonPath('data.category_id', $this->budget->category_id)
        ->assertJsonPath('data.version', 2);
});

test('an identical patch is 200 and changes nothing', function () {
    $before = $this->budget->fresh();

    patchBudgetCall($this->credential, $this->budget->id, ['amount_minor' => 600000, 'version' => 1])
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.change_seq', $before->change_seq);
});

test('a stale version is 409 with the current budget', function () {
    patchBudgetCall($this->credential, $this->budget->id, ['amount_minor' => 1, 'version' => 1])->assertOk();

    patchBudgetCall($this->credential, $this->budget->id, ['amount_minor' => 2, 'version' => 1])
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.amount_minor', 1);
});

test('a tombstone is 409 whatever the body', function () {
    $gone = ownedBudget($this->user->id, ['deleted_at' => now(), 'amount_minor' => 5]);

    patchBudgetCall($this->credential, $gone->id, ['amount_minor' => 5, 'version' => 1])
        ->assertValidResponse(409)
        ->assertJsonPath('current.deleted_at', fn ($at) => $at !== null);
});

test('an unknown id is 404, never a create', function () {
    patchBudgetCall($this->credential, (string) Str::uuid7(), ['amount_minor' => 5, 'version' => 1])->assertValidResponse(404);
});

test('an id that is not a UUIDv7 is 422 INVALID_ID', function () {
    patchBudgetCall($this->credential, 'nope', ['amount_minor' => 5, 'version' => 1])->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('invalid patches are refused with their field codes', function (array $body, string $pointer, string $code) {
    $response = patchBudgetCall($this->credential, $this->budget->id, $body)->assertStatus(422);

    expect($response->json('code'))->toBe('VALIDATION_FAILED')
        ->and(collect($response->json('errors'))->firstWhere('pointer', $pointer)['code'] ?? null)->toBe($code);
})->with([
    'no version' => [['amount_minor' => 5], '/version', 'REQUIRED'],
    'no limit' => [['version' => 1], '/amount_minor', 'REQUIRED'],
    'zero' => [['amount_minor' => 0, 'version' => 1], '/amount_minor', 'OUT_OF_RANGE'],
    'decimal' => [['amount_minor' => 1.5, 'version' => 1], '/amount_minor', 'AMOUNT_NOT_INTEGER'],
    'version zero' => [['amount_minor' => 5, 'version' => 0], '/version', 'OUT_OF_RANGE'],
    'category' => [['amount_minor' => 5, 'version' => 1, 'category_id' => null], '/category_id', 'UNKNOWN_FIELD'],
    'currency' => [['amount_minor' => 5, 'version' => 1, 'currency' => 'USD'], '/currency', 'UNKNOWN_FIELD'],
]);

test('the shared refusals', function () {
    $this->withHeaders(jsonHeaders())->patchJson('/api/v1/budgets/'.$this->budget->id, ['amount_minor' => 5, 'version' => 1])->assertValidResponse(401);
    $this->withHeaders([...asCredential($this->credential), 'X-Client-Version' => '0.0.1'])->patchJson('/api/v1/budgets/'.$this->budget->id, ['amount_minor' => 5, 'version' => 1])->assertStatus(426);
});

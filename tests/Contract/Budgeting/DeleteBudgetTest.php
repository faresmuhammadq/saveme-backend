<?php

use App\Modules\Budgeting\Infrastructure\Models\Budget;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * DELETE /budgets/{id} carries its version in a JSON body (spec 015, FR-006): a final tombstone.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->budget = ownedBudget($this->user->id);
});

function deleteBudgetCall(string $credential, string $id, array $body = ['version' => 1]): TestResponse
{
    return budgetCall('DELETE', $credential, '/budgets/'.$id, $body);
}

test('deleting returns the tombstone and the row stays', function () {
    $response = deleteBudgetCall($this->credential, $this->budget->id)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2);

    expect($response->json('data.deleted_at'))->not->toBeNull()
        ->and(Budget::query()->find($this->budget->id))->not->toBeNull();
});

test('deleting again is 200 with the same tombstone, whatever version is sent', function () {
    $first = deleteBudgetCall($this->credential, $this->budget->id)->json('data');

    deleteBudgetCall($this->credential, $this->budget->id, ['version' => 9])
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', $first['version'])
        ->assertJsonPath('data.deleted_at', $first['deleted_at']);
});

test('a stale version is 409 with the current budget and nothing is deleted', function () {
    budgetCall('PATCH', $this->credential, '/budgets/'.$this->budget->id, ['amount_minor' => 5, 'version' => 1])->assertOk();

    deleteBudgetCall($this->credential, $this->budget->id)
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.amount_minor', 5);

    expect($this->budget->fresh()->deleted_at)->toBeNull();
});

test('a deleted budget cannot be restored by any write', function () {
    deleteBudgetCall($this->credential, $this->budget->id)->assertOk();

    budgetCall('PUT', $this->credential, '/budgets/'.$this->budget->id, budgetBody(['category_id' => $this->budget->category_id, 'amount_minor' => $this->budget->amount_minor], version: 2))
        ->assertValidResponse(409);
    budgetCall('PATCH', $this->credential, '/budgets/'.$this->budget->id, ['amount_minor' => 5, 'version' => 2])->assertValidResponse(409);
});

test('an unknown id is 404, a bad id 422, no version 422 REQUIRED', function () {
    deleteBudgetCall($this->credential, (string) Str::uuid7())->assertValidResponse(404);
    deleteBudgetCall($this->credential, 'nope')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');

    $response = deleteBudgetCall($this->credential, $this->budget->id, [])->assertStatus(422);
    expect(collect($response->json('errors'))->firstWhere('pointer', '/version')['code'])->toBe('REQUIRED');
});

test('the shared refusals', function () {
    $this->withHeaders(jsonHeaders())->deleteJson('/api/v1/budgets/'.$this->budget->id, ['version' => 1])->assertValidResponse(401);
});

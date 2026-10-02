<?php

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * POST /budgets/{id}/archive (spec 015, FR-006): one way, carries its version.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
    $this->budget = ownedBudget($this->user->id);
});

function archiveBudgetCall(string $credential, string $id, array $body = ['version' => 1]): TestResponse
{
    return budgetCall('POST', $credential, '/budgets/'.$id.'/archive', $body);
}

test('archiving sets archived_at and moves the version', function () {
    $response = archiveBudgetCall($this->credential, $this->budget->id)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', 2);

    expect($response->json('data.archived_at'))->not->toBeNull();
});

test('archiving again is 200 and unchanged, whatever version is sent', function () {
    $first = archiveBudgetCall($this->credential, $this->budget->id)->json('data');

    archiveBudgetCall($this->credential, $this->budget->id, ['version' => 9])
        ->assertValidResponse(200)
        ->assertJsonPath('data.version', $first['version'])
        ->assertJsonPath('data.archived_at', $first['archived_at'])
        ->assertJsonPath('data.change_seq', $first['change_seq']);
});

test('an archived budget\'s limit stays editable', function () {
    archiveBudgetCall($this->credential, $this->budget->id)->assertOk();

    budgetCall('PATCH', $this->credential, '/budgets/'.$this->budget->id, ['amount_minor' => 5, 'version' => 2])->assertValidResponse(200)->assertJsonPath('data.amount_minor', 5);
});

test('a stale version is 409 with the current budget', function () {
    budgetCall('PATCH', $this->credential, '/budgets/'.$this->budget->id, ['amount_minor' => 5, 'version' => 1])->assertOk();

    archiveBudgetCall($this->credential, $this->budget->id)
        ->assertValidResponse(409)
        ->assertJsonPath('code', 'VERSION_CONFLICT')
        ->assertJsonPath('current.amount_minor', 5);
});

test('a tombstone is 409 and an unknown id 404', function () {
    $gone = ownedBudget($this->user->id, ['deleted_at' => now()]);

    archiveBudgetCall($this->credential, $gone->id)->assertValidResponse(409)->assertJsonPath('code', 'VERSION_CONFLICT');
    archiveBudgetCall($this->credential, (string) Str::uuid7())->assertValidResponse(404);
});

test('a body without a version is 422 REQUIRED, a bad id 422 INVALID_ID', function () {
    $response = archiveBudgetCall($this->credential, $this->budget->id, [])->assertStatus(422);

    expect(collect($response->json('errors'))->firstWhere('pointer', '/version')['code'])->toBe('REQUIRED');
    archiveBudgetCall($this->credential, 'nope')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('the shared refusals', function () {
    $this->withHeaders(jsonHeaders())->postJson('/api/v1/budgets/'.$this->budget->id.'/archive', ['version' => 1])->assertValidResponse(401);
});

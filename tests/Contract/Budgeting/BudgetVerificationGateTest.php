<?php

use App\Modules\Budgeting\Infrastructure\Models\Budget;
use Illuminate\Support\Str;

/*
 * Every budget write is behind the verification gate; reads are not (spec 015,
 * FR-023; security baseline C1).
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller('ada@example.test', verified: false);
    $this->budget = ownedBudget($this->user->id);
});

test('an unverified person is 403 EMAIL_NOT_VERIFIED on every write', function (string $method, string $uri, array $body) {
    $body = $method === 'PUT' ? budgetBody() : $body;

    budgetCall($method, $this->credential, str_replace('{id}', $this->budget->id, $uri), $body)
        ->assertValidResponse(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
})->with([
    'put' => ['PUT', '/budgets/{id}', []],
    'patch' => ['PATCH', '/budgets/{id}', ['amount_minor' => 5, 'version' => 1]],
    'archive' => ['POST', '/budgets/{id}/archive', ['version' => 1]],
    'delete' => ['DELETE', '/budgets/{id}', ['version' => 1]],
]);

test('the gate answers before the id is judged', function () {
    budgetCall('PUT', $this->credential, '/budgets/not-an-id', budgetBody())->assertStatus(403)->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

test('an unverified person can create nothing', function () {
    $before = Budget::query()->count();

    budgetCall('PUT', $this->credential, '/budgets/'.Str::uuid7(), budgetBody())->assertStatus(403);

    expect(Budget::query()->count())->toBe($before);
});

test('reads work for an unverified person', function () {
    budgetCall('GET', $this->credential, '/budgets')->assertValidResponse(200);
    budgetCall('GET', $this->credential, '/budgets/'.$this->budget->id)->assertValidResponse(200);
    budgetCall('GET', $this->credential, '/budgets/'.$this->budget->id.'/progress')->assertValidResponse(200);
    budgetCall('GET', $this->credential, '/budget-progress')->assertValidResponse(200);
});

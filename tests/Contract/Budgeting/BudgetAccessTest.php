<?php

use App\Modules\Budgeting\Infrastructure\Models\Budget;

/*
 * Another person's budget is not found, for every operation (spec 015, FR-016,
 * FR-017; P-08 rule 3 spirit). A shared wallet gives no right over a budget.
 */

beforeEach(function () {
    [$this->ada, $this->adaCredential] = walletCaller('ada@example.test');
    [$this->grace, $this->graceCredential] = walletCaller('grace@example.test');
    $this->hers = ownedBudget($this->grace->id, ['amount_minor' => 4242]);
    $wallet = ownedWallet($this->ada->id);
    addMember($wallet->id, $this->grace->id, 'editor');
});

test('every operation on another person\'s budget is 404 and changes nothing', function (string $method, string $uri, array $body) {
    $body = $method === 'PUT' ? budgetBody(version: 1) : $body;

    budgetCall($method, $this->adaCredential, str_replace('{id}', $this->hers->id, $uri), $body)
        ->assertValidResponse(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    $stored = Budget::query()->findOrFail($this->hers->id);
    expect($stored->amount_minor)->toBe(4242)->and($stored->version)->toBe(1)->and($stored->deleted_at)->toBeNull()->and($stored->archived_at)->toBeNull();
})->with([
    'get' => ['GET', '/budgets/{id}', []],
    'put' => ['PUT', '/budgets/{id}', []],
    'patch' => ['PATCH', '/budgets/{id}', ['amount_minor' => 5, 'version' => 1]],
    'archive' => ['POST', '/budgets/{id}/archive', ['version' => 1]],
    'delete' => ['DELETE', '/budgets/{id}', ['version' => 1]],
    'progress' => ['GET', '/budgets/{id}/progress', []],
]);

test('a create on another person\'s id is 404', function () {
    budgetCall('PUT', $this->adaCredential, '/budgets/'.$this->hers->id, budgetBody())->assertValidResponse(404);

    expect(Budget::query()->findOrFail($this->hers->id)->user_id)->toBe($this->grace->id);
});

test('lists and progress lists hold only the caller\'s budgets', function () {
    $mine = ownedBudget($this->ada->id);

    expect(collect(budgetCall('GET', $this->adaCredential, '/budgets')->assertValidResponse(200)->json('data'))->pluck('id')->all())->toBe([$mine->id])
        ->and(collect(budgetCall('GET', $this->adaCredential, '/budget-progress')->assertValidResponse(200)->json('data'))->pluck('budget_id')->all())->toBe([$mine->id]);
});

test('the methods a path does not offer are 405 with Allow', function (string $method, string $uri) {
    $response = budgetCall($method, $this->adaCredential, str_replace('{id}', $this->hers->id, $uri), []);

    $response->assertStatus(405);
    expect($response->headers->get('Allow'))->not->toBeNull();
})->with([
    'collection post' => ['POST', '/budgets'],
    'collection delete' => ['DELETE', '/budgets'],
    'item post' => ['POST', '/budgets/{id}'],
    'archive get' => ['GET', '/budgets/{id}/archive'],
    'archive put' => ['PUT', '/budgets/{id}/archive'],
    'progress post' => ['POST', '/budgets/{id}/progress'],
    'progress delete' => ['DELETE', '/budgets/{id}/progress'],
    'progress list post' => ['POST', '/budget-progress'],
    'progress list put' => ['PUT', '/budget-progress'],
]);

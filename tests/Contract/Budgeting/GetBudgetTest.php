<?php

use Illuminate\Support\Str;

/*
 * GET /budgets/{id} (spec 015, FR-017): the owner only, tombstone included.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
});

test('the owner reads the budget', function () {
    $budget = ownedBudget($this->user->id, ['amount_minor' => 123456]);

    budgetCall('GET', $this->credential, '/budgets/'.$budget->id)
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('data.id', $budget->id)
        ->assertJsonPath('data.amount_minor', 123456)
        ->assertJsonPath('data.user_id', $this->user->id);
});

test('the owner reads a tombstone with deleted_at set', function () {
    $budget = ownedBudget($this->user->id, ['deleted_at' => now()]);

    budgetCall('GET', $this->credential, '/budgets/'.$budget->id)
        ->assertValidResponse(200)
        ->assertJsonPath('data.deleted_at', fn ($at) => $at !== null);
});

test('an archived budget is read with archived_at set', function () {
    $budget = ownedBudget($this->user->id, ['archived_at' => now()]);

    budgetCall('GET', $this->credential, '/budgets/'.$budget->id)->assertValidResponse(200)->assertJsonPath('data.archived_at', fn ($at) => $at !== null);
});

test('an unknown id and a wallet id are 404, a malformed id is 422 INVALID_ID', function () {
    budgetCall('GET', $this->credential, '/budgets/'.Str::uuid7())->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
    budgetCall('GET', $this->credential, '/budgets/'.ownedWallet($this->user->id)->id)->assertValidResponse(404);
    budgetCall('GET', $this->credential, '/budgets/nope')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('unauthenticated is 401', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/budgets/'.Str::uuid7())->assertValidResponse(401);
});

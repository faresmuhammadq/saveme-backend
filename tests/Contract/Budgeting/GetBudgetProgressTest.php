<?php

use Illuminate\Support\Str;

/*
 * GET /budgets/{id}/progress (spec 015, FR-014, FR-016): computed on read; an
 * archived budget is served, a tombstone is 404.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
    $this->category = platformCategoryId();
    $this->budget = createBudget($this->user->id, ['category_id' => $this->category, 'amount_minor' => 600000]);
});

test('the current period carries every documented member', function () {
    spend($this->user->id, $this->wallet->id, 350000, ['date' => '2026-10-02']);
    spend($this->user->id, ownedWallet($this->user->id, ['currency' => 'USD'])->id, 900, ['currency' => 'USD']);

    $response = budgetCall('GET', $this->credential, '/budgets/'.$this->budget->id.'/progress')
        ->assertValidRequest()
        ->assertValidResponse(200);

    expect($response->json('data'))->toBe([
        'budget_id' => $this->budget->id,
        'period' => ['start' => '2026-10-01', 'end' => '2026-10-31', 'days_in_period' => 31, 'days_elapsed' => 15],
        'is_current' => true,
        'limit' => ['amount_minor' => 600000, 'currency' => 'EGP'],
        'spent' => ['amount_minor' => 350000, 'currency' => 'EGP'],
        'remaining' => ['amount_minor' => 250000, 'currency' => 'EGP'],
        'projected_end' => ['amount_minor' => 723334, 'currency' => 'EGP'],
        'over_limit' => false,
        'other_currencies' => ['USD'],
    ]);
});

test('a past period has no projection and no days elapsed', function () {
    spend($this->user->id, $this->wallet->id, 650000, ['date' => '2026-09-10']);

    $data = budgetCall('GET', $this->credential, '/budgets/'.$this->budget->id.'/progress?date=2026-09-10')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->json('data');

    expect($data['period'])->toBe(['start' => '2026-09-01', 'end' => '2026-09-30', 'days_in_period' => 30, 'days_elapsed' => null])
        ->and($data['is_current'])->toBeFalse()
        ->and($data['projected_end'])->toBeNull()
        ->and($data['remaining']['amount_minor'])->toBe(-50000)
        ->and($data['over_limit'])->toBeTrue();
});

test('an archived budget is served', function () {
    budgetCall('POST', $this->credential, '/budgets/'.$this->budget->id.'/archive', ['version' => 1])->assertOk();

    budgetCall('GET', $this->credential, '/budgets/'.$this->budget->id.'/progress')->assertValidResponse(200)->assertJsonPath('data.budget_id', $this->budget->id);
});

test('a tombstone and an unknown id are 404', function () {
    $gone = ownedBudget($this->user->id, ['deleted_at' => now()]);

    budgetCall('GET', $this->credential, '/budgets/'.$gone->id.'/progress')->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
    budgetCall('GET', $this->credential, '/budgets/'.Str::uuid7().'/progress')->assertValidResponse(404);
});

test('a bad id is 422 INVALID_ID', function () {
    budgetCall('GET', $this->credential, '/budgets/nope/progress')->assertValidResponse(422)->assertJsonPath('code', 'INVALID_ID');
});

test('a malformed or out-of-range date is 400 INVALID_QUERY_PARAMETER', function (string $date) {
    budgetCall('GET', $this->credential, '/budgets/'.$this->budget->id.'/progress?date='.$date)
        ->assertValidResponse(400)
        ->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
})->with(['2026-02-30', '2026-13-01', 'yesterday', '2026-1-5', '1899-12-31', '3000-01-01', '2026-10-15T10:00:00Z']);

test('the boundary dates are accepted', function (string $date) {
    budgetCall('GET', $this->credential, '/budgets/'.$this->budget->id.'/progress?date='.$date)->assertValidResponse(200);
})->with(['1900-01-01', '2999-12-31']);

test('unauthenticated is 401 and an old client 426', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/budgets/'.$this->budget->id.'/progress')->assertValidResponse(401);
    $this->withHeaders([...asCredential($this->credential), 'X-Client-Version' => '0.0.1'])->getJson('/api/v1/budgets/'.$this->budget->id.'/progress')->assertStatus(426);
});

<?php

/*
 * GET /budget-progress (spec 015, FR-016): every active budget for one Budget
 * Period; the cursor keeps its period.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    [$this->user, $this->credential] = walletCaller();
    $this->wallet = ownedWallet($this->user->id);
});

test('lists the progress of every active budget, archived and deleted left out', function () {
    $a = ownedBudget($this->user->id, ['created_at' => now()->subDays(3)]);
    $b = ownedBudget($this->user->id, ['created_at' => now()->subDays(2), 'category_id' => null]);
    ownedBudget($this->user->id, ['archived_at' => now()]);
    ownedBudget($this->user->id, ['deleted_at' => now()]);
    ownedBudget(person('grace@example.test')->id);
    spend($this->user->id, $this->wallet->id, 1000);

    $response = budgetCall('GET', $this->credential, '/budget-progress')->assertValidRequest()->assertValidResponse(200);

    expect(collect($response->json('data'))->pluck('budget_id')->all())->toBe([$a->id, $b->id])
        ->and($response->json('data.0.spent.amount_minor'))->toBe(1000)
        ->and($response->json('data.1.spent.amount_minor'))->toBe(1000)
        ->and($response->json('meta'))->toMatchArray(['has_more' => false, 'next_cursor' => null, 'limit' => 50]);
});

test('none is 200 with an empty list', function () {
    budgetCall('GET', $this->credential, '/budget-progress')->assertValidResponse(200)->assertJsonPath('data', []);
});

test('the default date is the owner\'s today and a date selects an earlier period', function () {
    ownedBudget($this->user->id);
    spend($this->user->id, $this->wallet->id, 700, ['date' => '2026-09-12']);

    budgetCall('GET', $this->credential, '/budget-progress')->assertValidResponse(200)->assertJsonPath('data.0.period.start', '2026-10-01')->assertJsonPath('data.0.spent.amount_minor', 0);
    budgetCall('GET', $this->credential, '/budget-progress?date=2026-09-12')->assertValidRequest()->assertValidResponse(200)
        ->assertJsonPath('data.0.period.start', '2026-09-01')
        ->assertJsonPath('data.0.is_current', false)
        ->assertJsonPath('data.0.spent.amount_minor', 700);
});

test('a walk keeps the period it began in, and every page is in it', function () {
    foreach (range(1, 3) as $i) {
        ownedBudget($this->user->id, ['created_at' => now()->subMinutes(10 - $i)]);
    }

    $first = budgetCall('GET', $this->credential, '/budget-progress?limit=1')->assertValidResponse(200);
    expect($first->json('meta.has_more'))->toBeTrue()->and($first->json('data.0.period.start'))->toBe('2026-10-01');

    // The day (and month) changes mid-walk: the cursor still answers the first period.
    freezeClock('2026-11-02 10:00:00');
    $seen = [$first->json('data.0.budget_id')];
    $cursor = $first->json('meta.next_cursor');
    while ($cursor !== null) {
        $page = budgetCall('GET', $this->credential, '/budget-progress?limit=1&cursor='.$cursor)->assertValidResponse(200);
        expect($page->json('data.0.period.start'))->toBe('2026-10-01');
        $seen[] = $page->json('data.0.budget_id');
        $cursor = $page->json('meta.next_cursor');
    }

    expect($seen)->toHaveCount(3)->and(array_unique($seen))->toHaveCount(3);
});

test('a cursor sent with a date in another period is 400 INVALID_CURSOR; with a date in the same period it is accepted', function () {
    ownedBudget($this->user->id, ['created_at' => now()->subMinutes(3)]);
    ownedBudget($this->user->id, ['created_at' => now()->subMinutes(2)]);

    $cursor = budgetCall('GET', $this->credential, '/budget-progress?limit=1&date=2026-10-05')->assertValidResponse(200)->json('meta.next_cursor');

    budgetCall('GET', $this->credential, '/budget-progress?limit=1&date=2026-09-05&cursor='.$cursor)->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
    budgetCall('GET', $this->credential, '/budget-progress?limit=1&date=2026-10-20&cursor='.$cursor)->assertValidResponse(200)->assertJsonPath('data.0.period.start', '2026-10-01');
});

test('a malformed date, a filter, an unsupported or reversed sort and a bad cursor are 400', function () {
    withoutSpectatorRequestValidation();

    budgetCall('GET', $this->credential, '/budget-progress?date=2026-02-30')->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
    budgetCall('GET', $this->credential, '/budget-progress?filter[currency]=EGP')->assertStatus(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    budgetCall('GET', $this->credential, '/budget-progress?sort=amount_minor')->assertStatus(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    budgetCall('GET', $this->credential, '/budget-progress?sort=-created_at')->assertStatus(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    budgetCall('GET', $this->credential, '/budget-progress?cursor=garbage')->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
});

test('a cursor from the budget list does not fit the progress list', function () {
    ownedBudget($this->user->id);
    ownedBudget($this->user->id);

    $cursor = budgetCall('GET', $this->credential, '/budgets?limit=1')->json('meta.next_cursor');

    budgetCall('GET', $this->credential, '/budget-progress?cursor='.$cursor)->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
});

test('unauthenticated is 401 and an old client 426', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/budget-progress')->assertValidResponse(401);
    $this->withHeaders([...asCredential($this->credential), 'X-Client-Version' => '0.0.1'])->getJson('/api/v1/budget-progress')->assertStatus(426);
});

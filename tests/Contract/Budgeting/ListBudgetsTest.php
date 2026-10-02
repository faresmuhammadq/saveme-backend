<?php

/*
 * GET /budgets (spec 015, FR-017): my live budgets, archived included, by created_at.
 */

beforeEach(function () {
    [$this->user, $this->credential] = walletCaller();
});

test('lists only my live budgets, archived included, tombstones and others\' left out', function () {
    $other = person('grace@example.test');
    $live = ownedBudget($this->user->id);
    $archived = ownedBudget($this->user->id, ['archived_at' => now()]);
    ownedBudget($this->user->id, ['deleted_at' => now()]);
    ownedBudget($other->id);

    $response = budgetCall('GET', $this->credential, '/budgets')->assertValidRequest()->assertValidResponse(200);

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$live->id, $archived->id])->sort()->values()->all())
        ->and($response->json('meta'))->toMatchArray(['has_more' => false, 'next_cursor' => null]);
});

test('none is 200 with an empty list, never 404', function () {
    budgetCall('GET', $this->credential, '/budgets')->assertValidResponse(200)->assertJsonPath('data', []);
});

test('sorted by created_at with the id as tiebreaker, descending with a minus', function () {
    $first = ownedBudget($this->user->id, ['created_at' => now()->subDays(2)]);
    $second = ownedBudget($this->user->id, ['created_at' => now()->subDays(1)]);
    $third = ownedBudget($this->user->id, ['created_at' => now()]);

    $ids = fn (string $query) => collect(budgetCall('GET', $this->credential, '/budgets'.$query)->assertValidResponse(200)->json('data'))->pluck('id')->all();

    expect($ids(''))->toBe([$first->id, $second->id, $third->id])
        ->and($ids('?sort=created_at'))->toBe([$first->id, $second->id, $third->id])
        ->and($ids('?sort=-created_at'))->toBe([$third->id, $second->id, $first->id]);
});

test('paging with limit and next_cursor visits every budget once', function () {
    $made = collect(range(1, 3))->map(fn (int $i) => ownedBudget($this->user->id, ['created_at' => now()->subMinutes(10 - $i)])->id)->all();

    $first = budgetCall('GET', $this->credential, '/budgets?limit=1')->assertValidResponse(200);
    expect($first->json('meta.has_more'))->toBeTrue();

    $seen = [$first->json('data.0.id')];
    $cursor = $first->json('meta.next_cursor');
    while ($cursor !== null) {
        $page = budgetCall('GET', $this->credential, '/budgets?limit=1&cursor='.$cursor)->assertValidResponse(200);
        $seen[] = $page->json('data.0.id');
        $cursor = $page->json('meta.next_cursor');
    }

    expect($seen)->toBe($made);
});

test('a filter, an unsupported sort, a bad limit and a foreign cursor are 400', function () {
    withoutSpectatorRequestValidation();

    budgetCall('GET', $this->credential, '/budgets?filter[currency]=EGP')->assertStatus(400)->assertJsonPath('code', 'FILTER_FIELD_NOT_SUPPORTED');
    budgetCall('GET', $this->credential, '/budgets?sort=amount_minor')->assertStatus(400)->assertJsonPath('code', 'SORT_FIELD_NOT_SUPPORTED');
    budgetCall('GET', $this->credential, '/budgets?limit=0')->assertStatus(400)->assertJsonPath('code', 'INVALID_QUERY_PARAMETER');
    budgetCall('GET', $this->credential, '/budgets?cursor=garbage')->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
});

test('a cursor from another endpoint is 400 INVALID_CURSOR', function () {
    ownedBudget($this->user->id);
    ownedBudget($this->user->id);

    $cursor = budgetCall('GET', $this->credential, '/budgets?limit=1')->json('meta.next_cursor');

    budgetCall('GET', $this->credential, '/budget-progress?cursor='.$cursor)->assertStatus(400)->assertJsonPath('code', 'INVALID_CURSOR');
});

test('unauthenticated is 401', function () {
    $this->withHeaders(jsonHeaders())->getJson('/api/v1/budgets')->assertValidResponse(401);
});

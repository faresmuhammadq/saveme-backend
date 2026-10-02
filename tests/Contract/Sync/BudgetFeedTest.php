<?php

use App\Modules\Budgeting\Application\BudgetService;

/*
 * Budgets on the user feed (spec 015, FR-019, US6 S3, US3 S5): `budget` rows by
 * the owner's counter beside profile, categories and memberships, each exactly
 * its read, tombstones included, never on a wallet feed.
 */

beforeEach(function () {
    [$this->ada, $this->adaCredential] = walletCaller('ada@example.test');
    $this->wallet = ownedWallet($this->ada->id);
});

test('budget rows are in position order with the other user-scope records and equal their reads', function () {
    $category = ownedCategory($this->ada->id);
    $first = createBudget($this->ada->id, ['category_id' => $category->id]);
    $second = createBudget($this->ada->id, ['category_id' => null, 'amount_minor' => 900000]);
    $gone = createBudget($this->ada->id, ['category_id' => null, 'amount_minor' => 100]);
    app(BudgetService::class)->delete($this->ada->id, $gone->id, 1, presentBudget());
    app(BudgetService::class)->archive($this->ada->id, $second->id, 1, presentBudget());

    $page = readFeed($this->adaCredential, 'user', null, 200)->assertValidRequest()->assertValidResponse(200);
    $rows = collect($page->json('data'));
    $budgetRows = $rows->where('type', 'budget');

    expect($budgetRows->pluck('record.id')->sort()->values()->all())->toBe(collect([$first->id, $second->id, $gone->id])->sort()->values()->all())
        ->and($rows->pluck('type')->unique()->all())->toContain('profile', 'category', 'wallet_member', 'budget');

    $positions = $rows->map(fn (array $row): int => $row['record']['change_seq'])->all();
    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted);

    foreach ($budgetRows as $row) {
        $read = budgetCall('GET', $this->adaCredential, '/budgets/'.$row['record']['id'])->assertOk()->json('data');
        expect($row['record'])->toBe($read);
    }

    expect($budgetRows->firstWhere('record.id', $gone->id)['record']['deleted_at'])->not->toBeNull()
        ->and($budgetRows->firstWhere('record.id', $second->id)['record']['archived_at'])->not->toBeNull();
});

test('a later change appears above the cursor, archive and delete included', function () {
    $budget = createBudget($this->ada->id);
    $cursor = readFeedFully($this->adaCredential, 'user')['cursor'];

    app(BudgetService::class)->archive($this->ada->id, $budget->id, 1, presentBudget());
    app(BudgetService::class)->delete($this->ada->id, $budget->id, 2, presentBudget());

    $rows = readFeedFully($this->adaCredential, 'user', $cursor)['rows'];

    expect(feedKeys($rows))->toBe(['budget:'.$budget->id])
        ->and($rows[0]['record']['version'])->toBe(3)
        ->and($rows[0]['record']['deleted_at'])->not->toBeNull();
});

test('a budget is never on a wallet feed and never on another person\'s user feed', function () {
    createBudget($this->ada->id);
    [$grace, $graceCredential] = walletCaller('grace@example.test');
    addMember($this->wallet->id, $grace->id, 'editor');

    expect(collect(readFeedFully($this->adaCredential, $this->wallet->id)['rows'])->where('type', 'budget'))->toHaveCount(0)
        ->and(collect(readFeedFully($graceCredential, $this->wallet->id)['rows'])->where('type', 'budget'))->toHaveCount(0)
        ->and(collect(readFeedFully($graceCredential, 'user')['rows'])->where('type', 'budget'))->toHaveCount(0);
});

test('paging with a small limit visits every budget once', function () {
    foreach (range(1, 4) as $_) {
        createBudget($this->ada->id);
    }

    $rows = [];
    $cursor = null;
    do {
        $page = readFeed($this->adaCredential, 'user', $cursor, 2)->assertValidResponse(200);
        $rows = [...$rows, ...$page->json('data')];
        $cursor = $page->json('meta.sync_cursor');
    } while ($page->json('meta.has_more'));

    $ids = collect($rows)->where('type', 'budget')->pluck('record.id');
    expect($ids)->toHaveCount(4)->and($ids->unique())->toHaveCount(4);
});

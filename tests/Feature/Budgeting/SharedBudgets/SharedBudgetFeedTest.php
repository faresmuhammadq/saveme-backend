<?php

use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
use App\Modules\Budgeting\Application\Sync\SharedBudgetTombstonePurge;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetResource;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sync\Application\ChangeFeedService;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use Illuminate\Support\Facades\DB;

/*
 * The Shared Budget scope's feed and its heads (ADR-0027 rule 9; ADR-0008;
 * ADR-0017 for the membership on the member's own user feed).
 */

function sharedBudgetFeed(string $reader, string $budgetId, ?string $cursor = null, int $limit = 100): array
{
    $result = app(ChangeFeedService::class)->sharedBudgetPage($reader, $budgetId, $cursor, $limit);

    return ['rows' => array_map(fn ($row) => ['type' => $row->type, 'position' => $row->position, 'record' => $row->record], $result->rows), 'result' => $result];
}

beforeEach(function () {
    $this->owner = User::factory()->create()->id;
    $this->partner = User::factory()->create()->id;
    $this->budget = createSharedBudget($this->owner);
    joinSharedBudget($this->budget, $this->partner);
    $this->wallet = ownedWallet($this->partner);
});

test('every row of the scope has a unique position and the budget holds the highest', function () {
    recordTransaction($this->partner, $this->wallet->id, transactionBody(['shared_budget_id' => $this->budget]));

    $rows = sharedBudgetFeed($this->owner, $this->budget)['rows'];
    $positions = array_column($rows, 'position');

    expect($positions)->toBe(array_values(array_unique($positions)))
        ->and(end($rows)['type'])->toBe('shared_budget')
        ->and(array_column($rows, 'type'))->toContain('shared_budget_member', 'shared_budget_entry');
});

test('a partner reads the owner\'s entry and never a wallet, memo or transaction id', function () {
    recordTransaction($this->partner, $this->wallet->id, transactionBody(['shared_budget_id' => $this->budget, 'memo' => 'hush', 'counterparty' => 'Sam']));

    $entry = collect(sharedBudgetFeed($this->owner, $this->budget)['rows'])->firstWhere('type', 'shared_budget_entry')['record'];

    expect(array_keys($entry))->not->toContain('wallet_id', 'memo', 'counterparty', 'transaction_id')
        ->and(json_encode($entry))->not->toContain($this->wallet->id)->not->toContain('hush')->not->toContain('Sam');
});

test('a cursor continues exactly where the page ended', function () {
    foreach (range(1, 3) as $i) {
        recordTransaction($this->partner, $this->wallet->id, transactionBody(['shared_budget_id' => $this->budget, 'amount_minor' => $i * 100]));
    }

    $first = sharedBudgetFeed($this->owner, $this->budget, null, 2);
    $second = sharedBudgetFeed($this->owner, $this->budget, $first['result']->syncCursor, 100);

    expect($first['result']->hasMore)->toBeTrue()
        ->and(array_column($second['rows'], 'position'))->each->toBeGreaterThan(end($first['rows'])['position']);
});

test('a non-member and a removed member read not-found', function () {
    $stranger = User::factory()->create()->id;
    app(SharedBudgetService::class)->removeMember($this->owner, $this->budget, $this->partner);

    expect(fn () => sharedBudgetFeed($stranger, $this->budget))->toThrow(SharedBudgetNotFound::class)
        ->and(fn () => sharedBudgetFeed($this->partner, $this->budget))->toThrow(SharedBudgetNotFound::class);
});

test('a membership rides its own member\'s user feed and a removal is a tombstone there', function () {
    foreach ([$this->owner, $this->partner] as $user) {
        Profile::factory()->create(['user_id' => $user]);
    }

    $feed = fn (string $user) => app(ChangeFeedService::class)->userPage($user, '00000000-0000-0000-0000-000000000000', null, 100)->rows;

    $mine = collect($feed($this->partner))->filter(fn ($row) => $row->type === 'shared_budget_member');
    expect($mine)->toHaveCount(1)->and($mine->first()->record['user_id'])->toBe($this->partner)->and($mine->first()->record['deleted_at'])->toBeNull();

    app(SharedBudgetService::class)->removeMember($this->owner, $this->budget, $this->partner);

    $after = collect($feed($this->partner))->filter(fn ($row) => $row->type === 'shared_budget_member');
    expect($after->last()->record['deleted_at'])->not->toBeNull()
        ->and(collect($feed($this->owner))->filter(fn ($row) => $row->type === 'shared_budget_member' && $row->record['user_id'] === $this->partner))->toHaveCount(0);
});

test('the purge removes expired tombstones, raises both horizons and keeps live rows', function () {
    $view = recordTransaction($this->partner, $this->wallet->id, transactionBody(['shared_budget_id' => $this->budget]));
    app(TransactionService::class)->delete($this->partner, $this->wallet->id, $view->transaction->id, 1, presentTransaction());
    app(SharedBudgetService::class)->removeMember($this->owner, $this->budget, $this->partner);
    DB::table('shared_budget_entries')->update(['deleted_at' => now()->subDays(120)]);
    DB::table('shared_budget_members')->whereNotNull('deleted_at')->update(['deleted_at' => now()->subDays(120)]);

    $removed = app(SharedBudgetTombstonePurge::class)->purge(now()->subDays(90)->toDateTimeImmutable());

    expect($removed['shared_budget_entries'])->toBe(1)
        ->and($removed['shared_budget_members'])->toBe(1)
        ->and((int) DB::table('shared_budgets')->where('id', $this->budget)->value('purged_through'))->toBeGreaterThan(0)
        ->and((int) DB::table('users')->where('id', $this->partner)->value('purged_change_seq'))->toBeGreaterThan(0)
        ->and(DB::table('shared_budget_members')->where('user_id', $this->owner)->count())->toBe(1);
});

test('a deleted budget goes whole once its tombstone has expired', function () {
    app(SharedBudgetService::class)->delete($this->owner, $this->budget, 1, SharedBudgetResource::toArray(...));
    DB::table('shared_budgets')->update(['deleted_at' => now()->subDays(120)]);
    DB::table('shared_budget_members')->update(['deleted_at' => now()->subDays(120)]);
    DB::table('shared_budget_lines')->update(['deleted_at' => now()->subDays(120)]);

    $removed = app(SharedBudgetTombstonePurge::class)->purge(now()->subDays(90)->toDateTimeImmutable());

    expect($removed['shared_budgets'])->toBe(1)->and(DB::table('shared_budgets')->count())->toBe(0);
});

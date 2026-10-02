<?php

use App\Modules\Budgeting\Application\BudgetService;
use App\Modules\Budgeting\Application\Sync\TombstonePurge;
use App\Modules\Budgeting\Infrastructure\Models\Budget;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sync\Application\TombstonePurgeRun;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\Categories\CategoryUsageQuery;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Facades\DB;

/*
 * The daily purge removes budget tombstones older than the horizon and raises
 * the owner's purge horizon (spec 015, FR-021, US6 S4; spec 013 R-11). The
 * budget step runs before Wallets', so a category freed by a purged budget
 * can go in the same run.
 */

function budgetTombstone(string $userId, array $changes = [], string $deletedAt = '2026-06-01 10:00:00'): Budget
{
    $budget = createBudget($userId, $changes);
    app(BudgetService::class)->delete($userId, $budget->id, 1, presentBudget());
    Budget::query()->whereKey($budget->id)->update(['deleted_at' => $deletedAt]);

    return Budget::query()->findOrFail($budget->id);
}

function purgedHorizonOf(string $userId): int
{
    return (int) DB::table('users')->where('id', $userId)->value('purged_change_seq');
}

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->owner = User::factory()->create()->id;
});

test('a budget tombstone older than 90 days is removed and the owner\'s horizon raised to its position', function () {
    $old = budgetTombstone($this->owner);

    $removed = app(TombstonePurgeRun::class)->handle();

    expect(Budget::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and($removed['budgeting_budgets'])->toBe(1)
        ->and(purgedHorizonOf($this->owner))->toBe($old->change_seq);
});

test('a younger tombstone, a live and an archived budget are kept and the horizon stays', function () {
    $young = budgetTombstone($this->owner, [], '2026-09-20 10:00:00');
    $live = createBudget($this->owner);
    $archived = createBudget($this->owner);
    app(BudgetService::class)->archive($this->owner, $archived->id, 1, presentBudget());

    $removed = app(TombstonePurgeRun::class)->handle();

    expect(Budget::query()->whereKey([$young->id, $live->id, $archived->id])->count())->toBe(3)
        ->and($removed['budgeting_budgets'])->toBe(0)
        ->and(purgedHorizonOf($this->owner))->toBe(0);
});

test('a second run finds nothing and the batches resume across more than one batch', function () {
    foreach (range(1, 3) as $_) {
        budgetTombstone($this->owner);
    }

    $first = app(TombstonePurge::class)->purge(now()->toDateTimeImmutable()->modify('-90 days'), 2);
    $second = app(TombstonePurgeRun::class)->handle();

    expect($first)->toBe(['budgets' => 3])
        ->and($second['budgeting_budgets'])->toBe(0)
        ->and(Budget::query()->count())->toBe(0);
});

test('a category used only by the purged budget becomes deletable', function () {
    $category = ownedCategory($this->owner);
    budgetTombstone($this->owner, ['category_id' => $category->id]);

    expect(app(CategoryUsageQuery::class)->isUsed($category->id))->toBeTrue();

    app(TombstonePurgeRun::class)->handle();

    expect(app(CategoryUsageQuery::class)->isUsed($category->id))->toBeFalse()
        ->and(app(CategoryService::class)->delete($this->owner, $category->id, 1, presentCategory())->category->deletedAt)->not->toBeNull();
});

test('a category tombstone freed by a purged budget in the same run goes in that run', function () {
    $category = ownedCategory($this->owner);
    budgetTombstone($this->owner, ['category_id' => $category->id]);
    Category::query()->whereKey($category->id)->update(['deleted_at' => '2026-06-01 10:00:00']);

    $removed = app(TombstonePurgeRun::class)->handle();

    expect($removed['budgeting_budgets'])->toBe(1)
        ->and(Category::query()->whereKey($category->id)->exists())->toBeFalse();
});

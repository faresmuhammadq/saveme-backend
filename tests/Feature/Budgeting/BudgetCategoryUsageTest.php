<?php

use App\Modules\Budgeting\Application\BudgetCategoryUsage;
use App\Modules\Budgeting\Application\BudgetService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\Categories\CategoryUsageQuery;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryInUse;
use Illuminate\Support\Facades\DB;

/*
 * A budgeted category is never deleted (spec 015, FR-018, US4 S6).
 */

beforeEach(function () {
    $this->owner = User::factory()->create()->id;
    $this->category = ownedCategory($this->owner);
});

test('a budgeted category is in use, so deleting it is refused and archiving still works', function () {
    createBudget($this->owner, ['category_id' => $this->category->id]);

    expect(app(CategoryUsageQuery::class)->isUsed($this->category->id))->toBeTrue()
        ->and(refusalOf(fn () => app(CategoryService::class)->delete($this->owner, $this->category->id, 1, presentCategory())))->toBeInstanceOf(CategoryInUse::class)
        ->and(app(CategoryService::class)->archive($this->owner, $this->category->id, 1, presentCategory())->category->archivedAt)->not->toBeNull();
});

test('a tombstoned budget still counts', function () {
    $budget = createBudget($this->owner, ['category_id' => $this->category->id]);
    app(BudgetService::class)->delete($this->owner, $budget->id, 1, presentBudget());

    expect(app(BudgetCategoryUsage::class)->isUsed($this->category->id))->toBeTrue();
});

test('an unbudgeted category is not in use, and an all-expenses budget names none', function () {
    createBudget($this->owner, ['category_id' => null]);

    expect(app(BudgetCategoryUsage::class)->isUsed($this->category->id))->toBeFalse()
        ->and(app(BudgetCategoryUsage::class)->usedAmong([$this->category->id]))->toBe([]);
});

test('usage is answered set-wise in a constant number of queries', function () {
    $categories = array_map(fn () => ownedCategory($this->owner)->id, range(1, 8));
    createBudget($this->owner, ['category_id' => $categories[2]]);
    createBudget($this->owner, ['category_id' => $categories[5]]);

    DB::enableQueryLog();
    $used = app(BudgetCategoryUsage::class)->usedAmong($categories);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($used)->toEqualCanonicalizing([$categories[2], $categories[5]])
        ->and($queries)->toBe(1)
        ->and(app(CategoryUsageQuery::class)->usedAmong($categories)[$categories[2]])->toBeTrue();
});

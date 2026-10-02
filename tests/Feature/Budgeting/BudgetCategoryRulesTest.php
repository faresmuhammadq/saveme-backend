<?php

use App\Modules\Budgeting\Application\BudgetService;
use App\Modules\Budgeting\Domain\Refusals\BudgetStateInvalid;
use App\Modules\Budgeting\Infrastructure\Models\Budget;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Which categories a budget may name (spec 015, FR-003, US1 S4-S5): an expense
 * category, live (archived or retired allowed), the writer's own or a
 * platform one. Under the category's share lock, so a delete and a budget
 * cannot both win.
 */

beforeEach(function () {
    $this->owner = User::factory()->create()->id;
    $this->partner = User::factory()->create()->id;
});

test('an own expense category, a platform expense, an archived own category and a retired platform category are accepted', function (Closure $category) {
    $id = $category($this);

    expect(saveBudget($this->owner, (string) Str::uuid7(), budgetBody(['category_id' => $id]))->created)->toBeTrue();
})->with([
    'own' => [fn ($t) => ownedCategory($t->owner)->id],
    'platform' => [fn () => platformCategoryId()],
    'own archived' => [fn ($t) => ownedCategory($t->owner, ['archived_at' => now()])->id],
    'retired platform' => [fn () => Category::factory()->platform()->expense()->create(['archived_at' => now()])->id],
]);

test('income, a partner\'s, an unknown, a deleted category and a transaction id all give the same answer', function (Closure $category) {
    $id = $category($this);

    $refusal = refusalOf(fn () => saveBudget($this->owner, (string) Str::uuid7(), budgetBody(['category_id' => $id])));

    expect($refusal)->toBeInstanceOf(BudgetStateInvalid::class)
        ->and(fieldErrorsOf($refusal))->toBe(['/category_id INVALID_ENUM_VALUE'])
        ->and(Budget::query()->count())->toBe(0);
})->with([
    'own income' => [fn ($t) => ownedCategory($t->owner, ['kind' => 'income'])->id],
    'platform income' => [fn () => platformCategoryId('income')],
    'partner\'s' => [fn ($t) => ownedCategory($t->partner)->id],
    'unknown' => [fn () => (string) Str::uuid7()],
    'deleted' => [fn ($t) => ownedCategory($t->owner, ['deleted_at' => now()])->id],
    'a transaction' => [function ($t) {
        $wallet = ownedWallet($t->owner);

        return spend($t->owner, $wallet->id, 100)->transaction->id;
    }],
]);

test('a save that leaves the category unchanged is never re-checked', function () {
    $category = ownedCategory($this->owner);
    $budget = createBudget($this->owner, ['category_id' => $category->id]);
    Category::query()->whereKey($category->id)->update(['kind' => 'income']);

    $saved = saveBudget($this->owner, $budget->id, budgetBody(['category_id' => $category->id, 'amount_minor' => 700000], version: 1));

    expect($saved->budget->amountMinor)->toBe(700000);
});

/*
 * Two real connections: the test commits the transaction RefreshDatabase
 * opened, works with committed rows, and deletes them afterwards.
 */

function onBudgetSession(string $connection, Closure $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

describe('concurrent category delete', function () {
    beforeEach(function () {
        while (DB::connection('pgsql')->transactionLevel() > 0) {
            DB::connection('pgsql')->commit();
        }

        config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
        DB::purge('pgsql_b');
        $this->category = Category::factory()->create(['user_id' => $this->owner]);
    });

    afterEach(function () {
        foreach (['pgsql', 'pgsql_b'] as $connection) {
            while (DB::connection($connection)->transactionLevel() > 0) {
                DB::connection($connection)->rollBack();
            }
        }

        DB::connection('pgsql')->statement('SET lock_timeout = 0');
        Budget::query()->delete();
        Category::query()->whereKey($this->category->id)->delete();
        User::query()->whereIn('id', [$this->owner, $this->partner])->delete();
        DB::purge('pgsql_b');
    });

    test('a budget waits for a category delete in flight, then sees it deleted', function () {
        DB::connection('pgsql_b')->beginTransaction();
        onBudgetSession('pgsql_b', fn () => app(CategoryRepository::class)->findOwnedForUpdate($this->category->id, $this->owner));

        DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");
        $blocked = false;
        try {
            saveBudget($this->owner, (string) Str::uuid7(), budgetBody(['category_id' => $this->category->id]));
        } catch (QueryException $exception) {
            $blocked = $exception->getCode() === '55P03';
        }
        DB::connection('pgsql')->statement('SET lock_timeout = 0');

        expect($blocked)->toBeTrue();

        onBudgetSession('pgsql_b', fn () => Category::query()->whereKey($this->category->id)->update(['deleted_at' => now()]));
        DB::connection('pgsql_b')->commit();

        expect(refusalOf(fn () => saveBudget($this->owner, (string) Str::uuid7(), budgetBody(['category_id' => $this->category->id]))))->toBeInstanceOf(BudgetStateInvalid::class)
            ->and(Budget::query()->count())->toBe(0);
    });

    test('a category delete waits for a budget being created on it, so the category stays', function () {
        DB::connection('pgsql')->beginTransaction();
        $id = (string) Str::uuid7();
        app(BudgetService::class)->save($this->owner, $id, budgetValues(['category_id' => $this->category->id]), 0, presentBudget());

        DB::connection('pgsql_b')->statement("SET lock_timeout = '300ms'");
        $blocked = false;
        onBudgetSession('pgsql_b', function () use (&$blocked): void {
            DB::beginTransaction();
            try {
                app(CategoryRepository::class)->findOwnedForUpdate($this->category->id, $this->owner);
            } catch (QueryException $exception) {
                $blocked = $exception->getCode() === '55P03';
            } finally {
                DB::rollBack();
            }
        });

        expect($blocked)->toBeTrue();

        DB::connection('pgsql')->commit();

        expect(Budget::query()->whereKey($id)->exists())->toBeTrue();
    });
});

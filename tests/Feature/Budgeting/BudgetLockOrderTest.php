<?php

use App\Modules\Budgeting\Infrastructure\Models\Budget;
use App\Modules\Identity\Application\Sync\UserScopeCounter;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Lock order (spec 015, ADR-0017 rule 5): the category's share lock, then the
 * owner's user counter, then the budget row. A budget create interleaved with
 * a category rename and with another category write by the same person always
 * completes: whoever waits waits at the first lock it needs, holding nothing
 * the other needs.
 *
 * Two sessions cannot see each other's uncommitted rows, so this test commits
 * the transaction RefreshDatabase opened, works with committed rows on two
 * connections, and deletes them afterwards.
 */

function inBudgetSession(string $connection, Closure $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function budgetWaitsOnALock(Closure $callback): bool
{
    DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");

    try {
        $callback();

        return false;
    } catch (QueryException $exception) {
        return $exception->getCode() === '55P03';
    } finally {
        DB::connection('pgsql')->statement('SET lock_timeout = 0');
    }
}

beforeEach(function () {
    while (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->commit();
    }

    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');

    $this->owner = User::factory()->create()->id;
    $this->category = Category::factory()->create(['user_id' => $this->owner, 'name' => 'Coffee']);
});

afterEach(function () {
    foreach (['pgsql', 'pgsql_b'] as $connection) {
        while (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    DB::connection('pgsql')->statement('SET lock_timeout = 0');
    Budget::query()->delete();
    Category::query()->where('user_id', $this->owner)->delete();
    User::query()->whereKey($this->owner)->delete();
    DB::purge('pgsql_b');
});

test('a budget create waits for a category rename at the category, takes no user lock, and then completes', function () {
    // B: a rename in flight holds the category row, and will stamp the user last.
    DB::connection('pgsql_b')->beginTransaction();
    inBudgetSession('pgsql_b', fn () => app(CategoryRepository::class)->findOwnedForUpdate($this->category->id, $this->owner));

    $blocked = budgetWaitsOnALock(fn () => saveBudget($this->owner, (string) Str::uuid7(), budgetBody(['category_id' => $this->category->id])));
    expect($blocked)->toBeTrue();

    // The waiting create held no user lock: B's stamp does not wait.
    $stamped = inBudgetSession('pgsql_b', fn () => app(UserScopeCounter::class)->next($this->owner));
    DB::connection('pgsql_b')->commit();

    expect($stamped)->toBeInt()->and(Budget::query()->count())->toBe(0);

    $created = saveBudget($this->owner, (string) Str::uuid7(), budgetBody(['category_id' => $this->category->id]));
    app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());

    expect($created->created)->toBeTrue()->and(Category::query()->findOrFail($this->category->id)->name)->toBe('Espresso');
});

test('a budget create holding the category share lock blocks a rename without deadlock, and both complete in turn', function () {
    DB::connection('pgsql')->beginTransaction();
    $id = (string) Str::uuid7();
    saveBudget($this->owner, $id, budgetBody(['category_id' => $this->category->id]));

    $renameWaits = false;
    DB::connection('pgsql_b')->statement("SET lock_timeout = '300ms'");
    inBudgetSession('pgsql_b', function () use (&$renameWaits): void {
        DB::beginTransaction();
        try {
            app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());
        } catch (QueryException $exception) {
            $renameWaits = $exception->getCode() === '55P03';
        } finally {
            DB::rollBack();
        }
    });
    DB::connection('pgsql_b')->statement('SET lock_timeout = 0');

    DB::connection('pgsql')->commit();

    expect($renameWaits)->toBeTrue()
        ->and(Budget::query()->whereKey($id)->exists())->toBeTrue();

    inBudgetSession('pgsql_b', fn () => app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory()));

    expect(Category::query()->findOrFail($this->category->id)->name)->toBe('Espresso');
});

test('a budget create and another category write by the same person interleave without deadlock', function () {
    // B: a second category write has taken the owner's counter and holds the user row.
    DB::connection('pgsql_b')->beginTransaction();
    inBudgetSession('pgsql_b', fn () => app(UserScopeCounter::class)->next($this->owner));

    // A: takes the category share lock (nothing of B's), then waits at the user counter.
    $blocked = budgetWaitsOnALock(fn () => saveBudget($this->owner, (string) Str::uuid7(), budgetBody(['category_id' => $this->category->id])));
    expect($blocked)->toBeTrue();

    // B needs the category only in share mode for its own write: A's aborted attempt left nothing behind.
    inBudgetSession('pgsql_b', fn () => app(CategoryRepository::class)->findForShare($this->category->id));
    DB::connection('pgsql_b')->commit();

    expect(saveBudget($this->owner, (string) Str::uuid7(), budgetBody(['category_id' => $this->category->id]))->created)->toBeTrue()
        ->and(Budget::query()->count())->toBe(1);
});

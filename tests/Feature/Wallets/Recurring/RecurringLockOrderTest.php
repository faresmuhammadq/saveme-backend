<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\RecurringRule;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Lock order (spec 014, FR-026; ADR-0014 rule 10): categories (share), then
 * wallets ascending, then the rule, then its occurrences. A category rename
 * and a rule or occurrence write, and a job pass and a rule edit, interleave
 * on two real connections without a deadlock.
 *
 * As in the lock-order tests of specs 011 and 012, the transaction
 * RefreshDatabase opened is committed so the second session sees the rows,
 * the "default" connection is switched between sessions, and the test
 * deletes its rows when done.
 */

function inRecurringSession(string $connection, Closure $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/**
 * Whether the callback was refused by a lock timeout (SQLSTATE 55P03).
 */
function waitsForALock(Closure $callback, string $connection = 'pgsql'): bool
{
    DB::connection($connection)->statement("SET lock_timeout = '300ms'");

    try {
        $callback();
    } catch (QueryException $exception) {
        return $exception->getCode() === '55P03';
    } finally {
        DB::connection($connection)->statement('SET lock_timeout = 0');
    }

    return false;
}

beforeEach(function () {
    while (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->commit();
    }

    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');
    freezeClock('2026-10-05 10:00:00');

    $this->owner = User::factory()->create()->id;
    $this->partner = User::factory()->create()->id;
    $this->category = Category::factory()->create(['user_id' => $this->owner, 'name' => 'Coffee']);
    $this->wallet = ownedWallet($this->partner);
    addMember($this->wallet->id, $this->owner, 'editor');
});

afterEach(function () {
    foreach (['pgsql', 'pgsql_b'] as $connection) {
        while (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    DB::connection('pgsql')->statement('SET lock_timeout = 0');
    Posting::query()->delete();
    Transaction::query()->delete();
    Occurrence::query()->delete();
    RecurringRule::query()->delete();
    CategoryReference::query()->delete();
    WalletMember::query()->delete();
    Wallet::query()->delete();
    Category::query()->whereNotNull('user_id')->delete();
    User::query()->whereIn('id', [$this->owner, $this->partner])->delete();
    DB::purge('pgsql_b');
});

test('a rename waits for a rule write holding the category and the wallet, then both commit with the newest name', function () {
    $body = recurringRuleBody(['category_id' => $this->category->id]);

    DB::connection('pgsql_b')->beginTransaction();
    inRecurringSession('pgsql_b', fn () => createRecurringRule($this->owner, $this->wallet->id, $body));

    expect(waitsForALock(fn () => app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory())))->toBeTrue();

    DB::connection('pgsql_b')->commit();

    $view = app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());

    expect($view->category->name)->toBe('Espresso')
        ->and(CategoryReference::query()->sole()->name)->toBe('Espresso')
        ->and(RecurringRule::query()->count())->toBe(1);
});

test('a rule write waits for a rename in flight and publishes the newest name', function () {
    DB::connection('pgsql')->beginTransaction();
    app(CategoryRepository::class)->findOwnedForUpdate($this->category->id, $this->owner);

    expect(waitsForALock(fn () => inRecurringSession('pgsql_b', fn () => createRecurringRule($this->owner, $this->wallet->id, recurringRuleBody(['category_id' => $this->category->id]))), 'pgsql_b'))->toBeTrue();

    app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());
    DB::connection('pgsql')->commit();

    inRecurringSession('pgsql_b', fn () => createRecurringRule($this->owner, $this->wallet->id, recurringRuleBody(['category_id' => $this->category->id])));

    expect(CategoryReference::query()->sole()->name)->toBe('Espresso');
});

test('an occurrence write waits for a rename in flight and a rule edit for the job, never deadlocking', function () {
    $rule = createRecurringRule($this->owner, $this->wallet->id, recurringRuleBody(['category_id' => $this->category->id, 'auto_confirm' => true]))->rule;
    $occurrence = occurrencesOf($rule->id)->first();

    DB::connection('pgsql')->beginTransaction();
    app(CategoryRepository::class)->findOwnedForUpdate($this->category->id, $this->owner);

    expect(waitsForALock(fn () => inRecurringSession('pgsql_b', fn () => saveRecurringOccurrence($this->partner, $this->wallet->id, $occurrence->id, occurrenceBody($occurrence, ['status' => 'skipped']))), 'pgsql_b'))->toBeTrue();

    DB::connection('pgsql')->rollBack();

    // The job holds the category, the wallet and the rule; a rule edit waits at the category, in the same order.
    freezeClock('2026-11-01 08:00:00');
    DB::connection('pgsql_b')->beginTransaction();
    inRecurringSession('pgsql_b', fn () => app(RecurringRuleService::class)->catchUp($rule->id));

    expect(waitsForALock(fn () => saveRecurringRule($this->owner, $this->wallet->id, $rule->id, recurringRuleBody(['category_id' => $this->category->id, 'auto_confirm' => true, 'amount_minor' => 5], 1))))->toBeTrue();

    DB::connection('pgsql_b')->commit();

    $edited = saveRecurringRule($this->owner, $this->wallet->id, $rule->id, recurringRuleBody(['category_id' => $this->category->id, 'auto_confirm' => true, 'amount_minor' => 5], 1));

    expect($edited->rule->version)->toBe(2)
        ->and(Occurrence::query()->whereKey($occurrence->id)->value('status'))->toBe('confirmed')
        ->and(Transaction::query()->count())->toBe(1);
});

test('a job pass waits for a rule edit in flight and then finds the rule as edited', function () {
    $rule = createRecurringRule($this->owner, $this->wallet->id, recurringRuleBody(['category_id' => $this->category->id, 'auto_confirm' => true]))->rule;
    freezeClock('2026-11-01 08:00:00');

    DB::connection('pgsql_b')->beginTransaction();
    inRecurringSession('pgsql_b', fn () => saveRecurringRule($this->partner, $this->wallet->id, $rule->id, recurringRuleBody(['category_id' => $this->category->id, 'auto_confirm' => true, 'amount_minor' => 7], 1)));

    expect(waitsForALock(fn () => app(RecurringRuleService::class)->catchUp($rule->id)))->toBeTrue();

    DB::connection('pgsql_b')->commit();
    app(RecurringRuleService::class)->catchUp($rule->id);

    expect(Transaction::query()->sole()->amount_minor)->toBe(7)
        ->and(refusalOf(fn () => saveRecurringRule($this->owner, $this->wallet->id, $rule->id, recurringRuleBody(['category_id' => $this->category->id, 'amount_minor' => 9], 1)))?->errorCode)->toBe(ErrorCode::VersionConflict);
});

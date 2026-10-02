<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
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
 * Two members, or a member and the job, confirm one occurrence at the same
 * moment (spec 014, US2 scenario 4, FR-019): exactly one transaction, the
 * loser a version conflict carrying the confirmed occurrence, no deadlock.
 *
 * As in the lock-order tests of specs 011 and 012, the transaction
 * RefreshDatabase opened is committed so the second session sees the rows,
 * the "default" connection is switched between sessions, and the test
 * deletes its rows when done.
 */

function asRecurringSession(string $connection, Closure $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
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
    $this->wallet = ownedWallet($this->owner);
    addMember($this->wallet->id, $this->partner, 'editor');
    $this->rule = createRecurringRule($this->owner, $this->wallet->id, recurringRuleBody(['auto_confirm' => true]))->rule;
    $this->occurrence = occurrencesOf($this->rule->id)->first();
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

/**
 * Whether the callback was refused by a lock timeout (SQLSTATE 55P03).
 */
function blockedByLock(Closure $callback): bool
{
    DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");

    try {
        $callback();
    } catch (QueryException $exception) {
        return $exception->getCode() === '55P03';
    } finally {
        DB::connection('pgsql')->statement('SET lock_timeout = 0');
    }

    return false;
}

test('two members confirming one occurrence: the second waits, then is a conflict with the confirmed occurrence', function () {
    $body = confirmBody($this->occurrence);
    $mine = confirmBody($this->occurrence);

    // Session B confirms and holds every lock, uncommitted.
    DB::connection('pgsql_b')->beginTransaction();
    asRecurringSession('pgsql_b', fn () => saveRecurringOccurrence($this->partner, $this->wallet->id, $this->occurrence->id, $body));

    // Session A takes the same locks in the same order, so it waits at the first it needs.
    expect(blockedByLock(fn () => saveRecurringOccurrence($this->owner, $this->wallet->id, $this->occurrence->id, $mine)))->toBeTrue();

    DB::connection('pgsql_b')->commit();

    $conflict = refusalOf(fn () => saveRecurringOccurrence($this->owner, $this->wallet->id, $this->occurrence->id, $mine));

    expect($conflict?->errorCode)->toBe(ErrorCode::VersionConflict)
        ->and($conflict->extensions['current']['status'])->toBe('confirmed')
        ->and($conflict->extensions['current']['transaction_id'])->toBe($body['transaction_id'])
        ->and(Transaction::query()->count())->toBe(1)
        ->and(walletBalance($this->wallet->id))->toBe(-450000);
});

test('a member confirming while the job confirms: one transaction, the member a conflict', function () {
    freezeClock('2026-11-01 08:00:00');
    $mine = confirmBody($this->occurrence);

    // Session B is the job pass for the rule, holding its locks, uncommitted.
    DB::connection('pgsql_b')->beginTransaction();
    asRecurringSession('pgsql_b', fn () => app(RecurringRuleService::class)->catchUp($this->rule->id));

    expect(blockedByLock(fn () => saveRecurringOccurrence($this->partner, $this->wallet->id, $this->occurrence->id, $mine)))->toBeTrue();

    DB::connection('pgsql_b')->commit();

    $conflict = refusalOf(fn () => saveRecurringOccurrence($this->partner, $this->wallet->id, $this->occurrence->id, $mine));

    expect($conflict?->errorCode)->toBe(ErrorCode::VersionConflict)
        ->and($conflict->extensions['current']['status'])->toBe('confirmed')
        ->and(Transaction::query()->count())->toBe(1)
        ->and(Transaction::query()->sole()->id)->not->toBe($mine['transaction_id']);
});

test('the job waits for a member\'s confirmation in flight and then finds nothing to do', function () {
    freezeClock('2026-11-01 08:00:00');

    DB::connection('pgsql_b')->beginTransaction();
    asRecurringSession('pgsql_b', fn () => saveRecurringOccurrence($this->partner, $this->wallet->id, $this->occurrence->id, confirmBody($this->occurrence)));

    expect(blockedByLock(fn () => app(RecurringRuleService::class)->catchUp($this->rule->id)))->toBeTrue();

    DB::connection('pgsql_b')->commit();
    $result = app(RecurringRuleService::class)->catchUp($this->rule->id);

    expect($result['confirmed'])->toBe(0)->and(Transaction::query()->count())->toBe(1);
});

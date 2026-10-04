<?php

use App\Modules\Billing\Application\Claims\ClaimService;
use App\Modules\Billing\Domain\Refusals\ClaimNotReviewable;
use App\Modules\Billing\Domain\Refusals\ReferenceAlreadyApproved;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Two operators approve at the same moment (spec 020, FR-020, FR-021): the
 * second waits on the first's lock, then is refused. As in the lock-order
 * tests of specs 011 and 012, the transaction RefreshDatabase opened is
 * committed so the second session sees the rows, the default connection is
 * switched between sessions, and the test deletes its rows when done.
 */

function asReviewSession(string $connection, Closure $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function reviewBlockedByLock(Closure $callback): bool
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

beforeEach(function () {
    while (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->commit();
    }

    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');
    Queue::fake();
    $this->operatorA = (string) Str::uuid7();
    $this->operatorB = (string) Str::uuid7();
});

afterEach(function () {
    foreach (['pgsql', 'pgsql_b'] as $connection) {
        while (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    DB::connection('pgsql')->statement('SET lock_timeout = 0');
    RedeemCode::query()->delete();
    PaymentClaim::query()->delete();
    DB::unprepared('TRUNCATE audit_entries');
    DB::purge('pgsql_b');
});

test('two approvals of one claim: the second waits, then is refused, and one code exists', function () {
    $claim = PaymentClaim::factory()->create();

    DB::connection('pgsql_b')->beginTransaction();
    asReviewSession('pgsql_b', fn () => app(ClaimService::class)->approve($this->operatorB, $claim->id));

    expect(reviewBlockedByLock(fn () => app(ClaimService::class)->approve($this->operatorA, $claim->id)))->toBeTrue();

    DB::connection('pgsql_b')->commit();

    expect(fn () => app(ClaimService::class)->approve($this->operatorA, $claim->id))->toThrow(ClaimNotReviewable::class)
        ->and(RedeemCode::query()->count())->toBe(1)
        ->and($claim->fresh()->reviewed_by)->toBe($this->operatorB);
});

test('two approvals of different claims with one reference: at most one succeeds', function () {
    $first = PaymentClaim::factory()->reference('RACEREF00001')->create();
    $second = PaymentClaim::factory()->reference('RACEREF00001')->create();

    DB::connection('pgsql_b')->beginTransaction();
    asReviewSession('pgsql_b', fn () => app(ClaimService::class)->approve($this->operatorB, $first->id));

    // The unique index on approved references makes the second wait for the first to commit.
    expect(reviewBlockedByLock(fn () => app(ClaimService::class)->approve($this->operatorA, $second->id)))->toBeTrue();

    DB::connection('pgsql_b')->commit();

    expect(fn () => app(ClaimService::class)->approve($this->operatorA, $second->id))->toThrow(ReferenceAlreadyApproved::class)
        ->and(PaymentClaim::query()->where('status', 'approved')->count())->toBe(1)
        ->and(RedeemCode::query()->count())->toBe(1)
        ->and($second->fresh()->status)->toBe('submitted');
});

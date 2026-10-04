<?php

use App\Modules\Billing\Application\RedeemCodes\RedeemCodes;
use App\Modules\Billing\Domain\RedeemCodes\RedeemCode;
use App\Modules\Billing\Infrastructure\Models\PaymentClaim;
use App\Modules\Billing\Infrastructure\Models\RedeemCode as RedeemCodeRow;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The same account redeems one code twice at the same moment (spec 020,
 * FR-032): exactly one subscription, the second call answers with it. As in
 * the lock-order tests of specs 011 and 012, the transaction RefreshDatabase
 * opened is committed so the second session sees the rows, the default
 * connection is switched between sessions, and the test deletes its rows when
 * done.
 */

function asRedeemSession(string $connection, Closure $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function redeemBlockedByLock(Closure $callback): bool
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
    $this->user = person('ada@example.test');
    $this->typed = RedeemCode::generate()->display();
    $claim = PaymentClaim::factory()->forAddress('ada@example.test')->approved()->create();
    RedeemCodeRow::factory()->forCode($this->typed)->state(['claim_id' => $claim->id])->create();
});

afterEach(function () {
    foreach (['pgsql', 'pgsql_b'] as $connection) {
        while (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    DB::connection('pgsql')->statement('SET lock_timeout = 0');
    Subscription::query()->delete();
    RedeemCodeRow::query()->delete();
    PaymentClaim::query()->delete();
    Profile::query()->where('user_id', $this->user->id)->delete();
    DB::unprepared('TRUNCATE audit_entries');
    DB::table('user_keys')->where('user_id', $this->user->id)->delete();
    User::query()->whereKey($this->user->id)->delete();
    DB::purge('pgsql_b');
});

test('two redemptions of one code by the same account: the second waits, then gets the same subscription', function () {
    DB::connection('pgsql_b')->beginTransaction();
    $first = asRedeemSession('pgsql_b', fn () => app(RedeemCodes::class)->redeem($this->user->id, $this->typed, 'en'));

    expect(redeemBlockedByLock(fn () => app(RedeemCodes::class)->redeem($this->user->id, $this->typed, 'en')))->toBeTrue();

    DB::connection('pgsql_b')->commit();

    $second = app(RedeemCodes::class)->redeem($this->user->id, $this->typed, 'en');

    expect($second->subscriptionId)->toBe($first->subscriptionId)
        ->and(Subscription::query()->count())->toBe(1);
});

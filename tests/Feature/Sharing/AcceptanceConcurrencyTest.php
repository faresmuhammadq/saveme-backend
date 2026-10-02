<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sharing\Application\InvitationResponses;
use App\Modules\Sharing\Domain\Refusals\TokenNotLive;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Two acceptances of one link on two real connections (spec 016, FR-014,
 * lock order of ADR-0017 rule 5): the wallet row lock serialises them, so the
 * second waits, then sees the invitation `accepted` and is refused: one
 * member, one refusal. As in the lock-order tests, the test commits the
 * transaction RefreshDatabase opened, works with committed rows and removes
 * them afterwards.
 */

beforeEach(function () {
    [$this->owner, , $this->wallet] = plusOwner();
    $this->first = person('first@example.test');
    $this->second = person('second@example.test');
    $this->link = pendingInvitation($this->owner->id, $this->wallet->id, null);

    while (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->commit();
    }

    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');
});

afterEach(function () {
    foreach (['pgsql', 'pgsql_b'] as $connection) {
        while (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    DB::connection('pgsql')->statement('SET lock_timeout = 0');
    $users = [$this->owner->id, $this->first->id, $this->second->id];
    DB::table('wallet_activity')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('invitations')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallet_members')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallets')->where('id', $this->wallet->id)->delete();
    DB::statement('TRUNCATE audit_entries');
    DB::table('subscriptions')->whereIn('user_id', $users)->delete();
    User::query()->whereIn('id', $users)->delete();
    DB::purge('pgsql_b');
});

function onSharingSession(string $connection, Closure $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

test('an acceptance waits for one in flight on the same wallet, then finds the link used', function () {
    // Connection B accepts first and holds its transaction open (and the wallet lock) until it commits.
    DB::connection('pgsql_b')->beginTransaction();
    onSharingSession('pgsql_b', fn () => app(InvitationResponses::class)->accept($this->first->id, $this->link['token']));

    DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");
    $blocked = false;
    try {
        app(InvitationResponses::class)->accept($this->second->id, $this->link['token']);
    } catch (QueryException $exception) {
        $blocked = $exception->getCode() === '55P03';
    }
    DB::connection('pgsql')->statement('SET lock_timeout = 0');
    expect($blocked)->toBeTrue();

    DB::connection('pgsql_b')->commit();

    expect(fn () => app(InvitationResponses::class)->accept($this->second->id, $this->link['token']))->toThrow(TokenNotLive::class)
        ->and(WalletMember::query()->where('wallet_id', $this->wallet->id)->whereNull('deleted_at')->count())->toBe(2)
        ->and(WalletMember::query()->where('user_id', $this->first->id)->exists())->toBeTrue()
        ->and(WalletMember::query()->where('user_id', $this->second->id)->exists())->toBeFalse();
});

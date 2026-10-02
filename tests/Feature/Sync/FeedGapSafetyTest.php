<?php

use App\Modules\Identity\Infrastructure\Models\DeviceSession;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * No committed record is ever skipped (spec 013, FR-020; ADR-0008 rules 3-5):
 * positions of a scope are commit-ordered, so paging between commits reads
 * every record. The second writer is a real second connection holding the
 * scope lock; as in the lock-order tests, the RefreshDatabase transaction is
 * committed so it can see the rows, and the test deletes what it made.
 */

function onConnection(string $connection, Closure $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

test('paging between 400 interleaved commits by two writers reads every record once, with unique positions', function () {
    [$ada, $adaCredential] = walletCaller('ada@example.test');
    [$grace] = walletCaller('grace@example.test');
    $wallet = ownedWallet($ada->id);
    addMember($wallet->id, $grace->id, 'editor');

    $written = [];
    $seen = [];
    $cursor = null;

    foreach (range(1, 200) as $n) {
        $written[] = recordTransaction($ada->id, $wallet->id)->transaction->id;
        $written[] = recordTransaction($grace->id, $wallet->id)->transaction->id;

        if ($n % 7 === 0) {
            $read = readFeedFully($adaCredential, $wallet->id, $cursor, 11);
            $cursor = $read['cursor'];
            foreach ($read['rows'] as $row) {
                $seen[$row['type'].':'.$row['record']['id']] = true;
            }
        }
    }

    $read = readFeedFully($adaCredential, $wallet->id, $cursor, 11);
    foreach ($read['rows'] as $row) {
        $seen[$row['type'].':'.$row['record']['id']] = true;
    }

    expect(array_diff(array_map(fn (string $id): string => 'transaction:'.$id, $written), array_keys($seen)))->toBe([]);
    expectTransactionScopeIntact($wallet->id);
});

test('on the user scope a category save and a session end interleave without a skipped record', function () {
    [$ada, $adaCredential] = walletCaller('ada@example.test');
    $expected = [];
    $seen = [];
    $cursor = null;

    foreach (range(1, 60) as $n) {
        $id = (string) Str::uuid7();
        app(CategoryService::class)->save($ada->id, $id, categoryValues(['name' => "C{$n}"]), 0, presentCategory());
        $expected[] = 'category:'.$id;
        $session = DeviceSession::factory()->create(['user_id' => $ada->id]);
        $expected[] = 'device_session:'.$session->id;

        if ($n % 5 === 0) {
            $read = readFeedFully($adaCredential, 'user', $cursor, 9);
            $cursor = $read['cursor'];
            foreach ($read['rows'] as $row) {
                $seen[$row['type'].':'.$row['record']['id']] = true;
            }
        }
    }

    $read = readFeedFully($adaCredential, 'user', $cursor, 9);
    foreach ($read['rows'] as $row) {
        $seen[$row['type'].':'.$row['record']['id']] = true;
    }

    expect(array_diff($expected, array_keys($seen)))->toBe([]);
});

test('writes committed from a second connection and the first are read in commit order', function () {
    [$ada, $adaCredential] = walletCaller('ada@example.test');
    $wallet = ownedWallet($ada->id);

    while (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->commit();
    }

    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');

    try {
        $cursor = readFeedFully($adaCredential, $wallet->id)['cursor'];

        // Connection B commits first; the first connection commits after it.
        DB::connection('pgsql_b')->beginTransaction();
        $pending = (string) Str::uuid7();
        onConnection('pgsql_b', fn () => saveTransaction($ada->id, $wallet->id, $pending, transactionBody()));
        DB::connection('pgsql_b')->commit();

        $first = (string) Str::uuid7();
        saveTransaction($ada->id, $wallet->id, $first, transactionBody());

        $rows = readFeedFully($adaCredential, $wallet->id, $cursor)['rows'];
        $keys = feedKeys($rows);
        expect($keys)->toContain('transaction:'.$pending, 'transaction:'.$first)
            ->and(array_search('transaction:'.$pending, $keys, true))->toBeLessThan(array_search('transaction:'.$first, $keys, true));
    } finally {
        foreach (['pgsql', 'pgsql_b'] as $connection) {
            while (DB::connection($connection)->transactionLevel() > 0) {
                DB::connection($connection)->rollBack();
            }
        }

        DB::table('postings')->delete();
        DB::table('transactions')->delete();
        DB::table('category_references')->delete();
        DB::table('wallet_members')->delete();
        Wallet::query()->delete();
        DB::table('device_sessions')->delete();
        DB::table('profiles')->delete();
        DB::table('users')->delete();
        DB::purge('pgsql_b');
    }
});

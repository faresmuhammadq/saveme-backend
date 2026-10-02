<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Categories\CategoryReferences;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Lock order (spec 013, FR-020; ADR-0017 rule 5): category rows, then wallet
 * rows ascending, then user rows ascending. A category rename that
 * republishes into a shared wallet and an ownership transfer of that wallet
 * involve the same two people on two real connections. Each is held open
 * while the other is tried: the second waits (it never deadlocks), both
 * commit, and each person's counter advances by exactly the stamps taken.
 *
 * As in the other lock-order tests, the RefreshDatabase transaction is
 * committed so the second session sees the rows, and the test deletes them.
 */

function inSession(string $connection, Closure $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function waitsOn(string $connection, Closure $attempt): bool
{
    DB::connection($connection)->statement("SET lock_timeout = '300ms'");

    try {
        $attempt();

        return false;
    } catch (QueryException $exception) {
        return $exception->getCode() === '55P03';
    } finally {
        DB::connection($connection)->statement('SET lock_timeout = 0');
    }
}

function counterOf(string $userId): int
{
    return (int) DB::connection('pgsql')->table('users')->where('id', $userId)->value('change_seq');
}

beforeEach(function () {
    // The plan-demotion listener (spec 016) is queued; these tests are about the transfer itself, and the demotion has its own tests.
    Queue::fake();
    while (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->commit();
    }

    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');

    $this->ada = User::factory()->create()->id;
    $this->grace = User::factory()->create()->id;
    $this->category = Category::factory()->create(['user_id' => $this->ada, 'name' => 'Coffee']);
    $this->wallet = ownedWallet($this->grace);
    addMember($this->wallet->id, $this->ada, 'editor');
    app(CategoryReferences::class);
    DB::transaction(fn () => app(CategoryReferences::class)->publish($this->wallet->id, $this->category->id));
});

afterEach(function () {
    foreach (['pgsql', 'pgsql_b'] as $connection) {
        while (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    DB::connection('pgsql')->statement('SET lock_timeout = 0');
    DB::statement('TRUNCATE audit_entries');
    CategoryReference::query()->delete();
    WalletMember::query()->where('wallet_id', $this->wallet->id)->delete();
    Wallet::query()->whereKey($this->wallet->id)->delete();
    Category::query()->whereKey($this->category->id)->delete();
    User::query()->whereIn('id', [$this->ada, $this->grace])->delete();
    DB::purge('pgsql_b');
});

test('a rename and an ownership transfer over the same two people wait for each other without deadlock, repeatedly', function () {
    $adaStart = counterOf($this->ada);
    $graceStart = counterOf($this->grace);
    $owner = $this->grace;
    $other = $this->ada;
    $walletVersion = 1;
    $categoryVersion = 1;
    $adaStamps = 0;
    $graceStamps = 0;

    foreach (range(1, 3) as $round) {
        // B holds a transfer open (wallet, then both people); the rename waits at the wallet.
        DB::connection('pgsql_b')->beginTransaction();
        inSession('pgsql_b', fn () => app(WalletService::class)->transferOwnership($owner, $this->wallet->id, $other, $walletVersion, presentWallet()));
        $walletVersion++;

        expect(waitsOn('pgsql', fn () => app(CategoryService::class)->patch($this->ada, $this->category->id, ['name' => "Round {$round}a"], $categoryVersion, presentCategory())))->toBeTrue();

        DB::connection('pgsql_b')->commit();
        [$owner, $other] = [$other, $owner];

        app(CategoryService::class)->patch($this->ada, $this->category->id, ['name' => "Round {$round}a"], $categoryVersion, presentCategory());
        $categoryVersion++;

        // A holds a rename open (category, wallet, then ada); the transfer waits at the wallet.
        DB::connection('pgsql')->beginTransaction();
        app(CategoryService::class)->patch($this->ada, $this->category->id, ['name' => "Round {$round}b"], $categoryVersion, presentCategory());
        $categoryVersion++;

        expect(waitsOn('pgsql_b', fn () => inSession('pgsql_b', fn () => app(WalletService::class)->transferOwnership($owner, $this->wallet->id, $other, $walletVersion, presentWallet()))))->toBeTrue();

        DB::connection('pgsql')->commit();

        inSession('pgsql_b', fn () => app(WalletService::class)->transferOwnership($owner, $this->wallet->id, $other, $walletVersion, presentWallet()));
        $walletVersion++;
        [$owner, $other] = [$other, $owner];

        // Two transfers (a stamp for each person) and two renames (a stamp for ada) per round.
        $adaStamps += 2 + 2;
        $graceStamps += 2;
    }

    expect(counterOf($this->ada))->toBe($adaStart + $adaStamps)
        ->and(counterOf($this->grace))->toBe($graceStart + $graceStamps)
        ->and(CategoryReference::query()->sole()->name)->toBe('Round 3b');
});

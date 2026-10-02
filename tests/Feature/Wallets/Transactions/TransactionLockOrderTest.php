<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Lock order (spec 012, FR-011, R-4; ADR-0014 rule 10): categories first
 * (share), then wallets in ascending id. A category rename and a transaction
 * write in a shared wallet, and two writes to one transfer from its two
 * paths, interleave on two real connections without a deadlock.
 *
 * As in spec 011's lock-order test, the transaction RefreshDatabase opened is
 * committed so the second session sees the rows, the "default" connection is
 * switched between sessions, and the test deletes its rows when done.
 */

function asConnection(string $connection, Closure $callback): mixed
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

    $this->owner = User::factory()->create()->id;
    $this->partner = User::factory()->create()->id;
    $this->category = Category::factory()->create(['user_id' => $this->owner, 'name' => 'Coffee']);
    $this->wallets = [ownedWallet($this->partner), ownedWallet($this->partner)];
    foreach ($this->wallets as $wallet) {
        addMember($wallet->id, $this->owner, 'editor');
    }
});

afterEach(function () {
    foreach (['pgsql', 'pgsql_b'] as $connection) {
        while (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    DB::connection('pgsql')->statement('SET lock_timeout = 0');
    $ids = array_map(fn (Wallet $wallet): string => $wallet->id, $this->wallets);
    $transactionIds = Transaction::query()->whereIn('wallet_id', $ids)->pluck('id');
    Posting::query()->whereIn('transaction_id', $transactionIds)->delete();
    Transaction::query()->whereIn('id', $transactionIds)->delete();
    CategoryReference::query()->delete();
    WalletMember::query()->whereIn('wallet_id', $ids)->delete();
    Wallet::query()->whereIn('id', $ids)->delete();
    Category::query()->whereKey($this->category->id)->delete();
    User::query()->whereIn('id', [$this->owner, $this->partner])->delete();
    DB::purge('pgsql_b');
});

test('a rename waits for a transaction write holding the category and the wallets, then both commit with the newest name', function () {
    [$wallet] = $this->wallets;
    $body = transactionBody(['category_id' => $this->category->id]);

    // Session B: a transaction write, in the documented order, left uncommitted.
    DB::connection('pgsql_b')->beginTransaction();
    asConnection('pgsql_b', fn () => saveTransaction($this->owner, $wallet->id, (string) Str::uuid7(), $body));

    // Session A: the rename needs the category row first, so it waits there before touching any wallet.
    DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");
    $blocked = false;
    try {
        app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());
    } catch (QueryException $exception) {
        $blocked = $exception->getCode() === '55P03';
    }
    DB::connection('pgsql')->statement('SET lock_timeout = 0');

    expect($blocked)->toBeTrue();

    DB::connection('pgsql_b')->commit();

    $view = app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());

    expect($view->category->name)->toBe('Espresso')
        ->and(CategoryReference::query()->sole()->name)->toBe('Espresso')
        ->and(Transaction::query()->count())->toBe(1);
});

test('a transaction write waits for a rename in flight and publishes the newest name', function () {
    [$wallet] = $this->wallets;

    // Session A starts a rename and holds the category row without committing.
    DB::connection('pgsql')->beginTransaction();
    app(CategoryRepository::class)->findOwnedForUpdate($this->category->id, $this->owner);

    // Session B's write needs the category in share mode first: it waits, holding no wallet.
    DB::connection('pgsql_b')->statement("SET lock_timeout = '300ms'");
    $blocked = false;
    asConnection('pgsql_b', function () use ($wallet, &$blocked): void {
        try {
            saveTransaction($this->owner, $wallet->id, (string) Str::uuid7(), transactionBody(['category_id' => $this->category->id]));
        } catch (QueryException $exception) {
            $blocked = $exception->getCode() === '55P03';
        }
    });
    DB::connection('pgsql_b')->statement('SET lock_timeout = 0');

    expect($blocked)->toBeTrue();

    app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());
    DB::connection('pgsql')->commit();

    asConnection('pgsql_b', fn () => saveTransaction($this->owner, $wallet->id, (string) Str::uuid7(), transactionBody(['category_id' => $this->category->id])));

    expect(CategoryReference::query()->sole()->name)->toBe('Espresso');
});

test('two writes to one transfer from its two paths wait for each other and never deadlock; the second gets a conflict', function () {
    [$source, $destination] = $this->wallets;
    $id = (string) Str::uuid7();
    saveTransaction($this->owner, $source->id, $id, transferBody($destination->id, ['amount_minor' => 500]));

    // Session B edits through the source's path and holds both wallets.
    DB::connection('pgsql_b')->beginTransaction();
    asConnection('pgsql_b', fn () => app(TransactionService::class)->patch($this->owner, $source->id, $id, ['amount_minor' => 700], 1, presentTransaction()));

    // Session A edits through the destination's path: the same ascending order, so it waits at the first wallet.
    DB::connection('pgsql')->statement("SET lock_timeout = '300ms'");
    $blocked = false;
    try {
        app(TransactionService::class)->patch($this->owner, $destination->id, $id, ['amount_minor' => 900], 1, presentTransaction());
    } catch (QueryException $exception) {
        $blocked = $exception->getCode() === '55P03';
    }
    DB::connection('pgsql')->statement('SET lock_timeout = 0');

    expect($blocked)->toBeTrue();

    DB::connection('pgsql_b')->commit();

    $conflict = refusalOf(fn () => app(TransactionService::class)->patch($this->owner, $destination->id, $id, ['amount_minor' => 900], 1, presentTransaction()));

    expect($conflict?->errorCode)->toBe(ErrorCode::VersionConflict)
        ->and($conflict->extensions['current']['amount_minor'])->toBe(700)
        ->and(Transaction::query()->find($id)->amount_minor)->toBe(700);
});

<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Categories\CategoryReferences;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Domain\Categories\Ports\CategoryRepository;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Lock order (spec 011, FR-023, ADR-0014 rule 10): category rows first, then
 * wallet rows. A rename and a simulated B6 write interleave on two real
 * connections without a deadlock, and the reference ends with the newest
 * name.
 *
 * Two sessions cannot see each other's uncommitted rows, so this test commits
 * the transaction RefreshDatabase opened, works with committed rows on two
 * connections (the "default" session is switched between them), and deletes
 * its rows when it is done.
 */

/**
 * Runs the callback as the other database session: the application's default
 * connection is switched for its duration.
 */
function asSession(string $connection, Closure $callback): mixed
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
    // Commit the test transaction RefreshDatabase opened: the second session must see the rows.
    while (DB::connection('pgsql')->transactionLevel() > 0) {
        DB::connection('pgsql')->commit();
    }

    config(['database.connections.pgsql_b' => config('database.connections.pgsql')]);
    DB::purge('pgsql_b');

    $this->owner = User::factory()->create()->id;
    $this->partner = User::factory()->create()->id;
    $this->category = Category::factory()->create(['user_id' => $this->owner, 'name' => 'Coffee']);
    $this->wallets = [ownedWallet($this->partner), ownedWallet($this->partner)];
});

afterEach(function () {
    foreach (['pgsql', 'pgsql_b'] as $connection) {
        while (DB::connection($connection)->transactionLevel() > 0) {
            DB::connection($connection)->rollBack();
        }
    }

    DB::connection('pgsql')->statement('SET lock_timeout = 0');
    CategoryReference::query()->delete();
    WalletMember::query()->whereIn('wallet_id', array_map(fn (Wallet $wallet): string => $wallet->id, $this->wallets))->delete();
    Wallet::query()->whereIn('id', array_map(fn (Wallet $wallet): string => $wallet->id, $this->wallets))->delete();
    Category::query()->whereKey($this->category->id)->delete();
    User::query()->whereIn('id', [$this->owner, $this->partner])->delete();
    DB::purge('pgsql_b');
});

test('a rename waits for a writer holding the category and the wallets, then both commit with the newest name', function () {
    [$first, $second] = $this->wallets;

    // Session B: a simulated B6 write, in the documented order: category (share), then wallets, then publish.
    DB::connection('pgsql_b')->beginTransaction();
    asSession('pgsql_b', function () use ($first, $second): void {
        app(CategoryRepository::class)->findForShare($this->category->id);
        app(CategoryReferences::class)->publish($first->id, $this->category->id);
        app(CategoryReferences::class)->publish($second->id, $this->category->id);
    });

    // Session A: the rename needs the category row first, so it waits there, before touching any wallet.
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

    // B has committed; the rename now goes through and republishes both references.
    $view = app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());

    expect($view->category->name)->toBe('Espresso')
        ->and(CategoryReference::query()->pluck('name')->unique()->all())->toBe(['Espresso'])
        ->and(CategoryReference::query()->count())->toBe(2)
        ->and(CategoryReference::query()->pluck('version')->unique()->all())->toBe([2]);
});

test('a writer waits for a rename in flight and publishes the newest name', function () {
    [$first] = $this->wallets;

    // Session A starts a rename and holds the category row (update lock) without committing.
    DB::connection('pgsql')->beginTransaction();
    app(CategoryRepository::class)->findOwnedForUpdate($this->category->id, $this->owner);

    // Session B's simulated write needs the category in share mode first: it waits, holding no wallet.
    DB::connection('pgsql_b')->statement("SET lock_timeout = '300ms'");
    $blocked = false;
    asSession('pgsql_b', function () use ($first, &$blocked): void {
        DB::beginTransaction();
        try {
            app(CategoryRepository::class)->findForShare($this->category->id);
            app(CategoryReferences::class)->publish($first->id, $this->category->id);
        } catch (QueryException $exception) {
            $blocked = $exception->getCode() === '55P03';
        } finally {
            DB::rollBack();
        }
    });
    DB::connection('pgsql_b')->statement('SET lock_timeout = 0');

    expect($blocked)->toBeTrue();

    // A finishes its rename and commits; B retries and publishes the newest name.
    app(CategoryService::class)->patch($this->owner, $this->category->id, ['name' => 'Espresso'], 1, presentCategory());
    DB::connection('pgsql')->commit();

    asSession('pgsql_b', function () use ($first): void {
        DB::transaction(function () use ($first): void {
            app(CategoryRepository::class)->findForShare($this->category->id);
            app(CategoryReferences::class)->publish($first->id, $this->category->id);
        });
    });

    expect(CategoryReference::query()->sole()->name)->toBe('Espresso');
});

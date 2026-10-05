<?php

use App\Modules\Identity\Application\Accounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Wallets\Application\Categories\CategoryReferences;
use App\Modules\Wallets\Application\Departure\ReleaseCategories;
use App\Modules\Wallets\Application\Sync\TombstonePurge;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Wallets' categories step of an account purge (spec 022, FR-046): unused
 * categories are tombstoned and removed with the user scope's horizon raised,
 * used ones archived and kept until nothing refers to them.
 */

beforeEach(function () {
    $this->d = person();
    $this->wallet = ownedWallet($this->d->id);
});

test('an unused category is removed, a used one is archived and kept, counts are returned, and a second call is a no-op', function () {
    $unused = ownedCategory($this->d->id, ['name' => 'Unused']);
    $used = ownedCategory($this->d->id, ['name' => 'Used']);
    recordTransaction($this->d->id, $this->wallet->id, transactionBody(['category_id' => $used->id]));
    $step = app(ReleaseCategories::class);

    $counts = $step->purge($this->d->id, purgeContextFor($this->d->id));

    expect($step->step())->toBe(PurgeStep::Categories)
        ->and($counts->get('categories_deleted'))->toBe(1)
        ->and($counts->get('categories_kept'))->toBe(1)
        ->and(Category::query()->find($unused->id))->toBeNull()
        ->and(Category::query()->find($used->id)->archived_at)->not->toBeNull()
        ->and(Category::query()->find($used->id)->user_id)->toBe($this->d->id)
        ->and((int) DB::table('users')->where('id', $this->d->id)->value('purged_change_seq'))->toBeGreaterThanOrEqual((int) $unused->fresh()?->change_seq);

    $version = Category::query()->find($used->id)->version;
    $again = $step->purge($this->d->id, purgeContextFor($this->d->id));

    expect($again->get('categories_deleted'))->toBe(0)
        ->and(Category::query()->find($used->id)->version)->toBe($version);
});

test('a category published into a wallet is unpublished and removed or tombstoned, never left live', function () {
    $published = ownedCategory($this->d->id, ['name' => 'Published']);
    app(CategoryReferences::class)->publish($this->wallet->id, $published->id);
    expect(DB::table('category_references')->where('category_id', $published->id)->whereNull('deleted_at')->count())->toBe(1);

    app(ReleaseCategories::class)->purge($this->d->id, purgeContextFor($this->d->id));

    expect(DB::table('category_references')->where('category_id', $published->id)->whereNull('deleted_at')->count())->toBe(0);
    $row = DB::table('categories')->where('id', $published->id)->first();
    expect($row === null || $row->deleted_at !== null)->toBeTrue();
});

test('an archived category of a departed owner goes in the daily sweep once nothing uses it', function () {
    $used = ownedCategory($this->d->id, ['name' => 'Used']);
    $entry = recordTransaction($this->d->id, $this->wallet->id, transactionBody(['category_id' => $used->id]))->transaction;
    app(ReleaseCategories::class)->purge($this->d->id, purgeContextFor($this->d->id));

    $purge = app(TombstonePurge::class);

    // The owner is still leaving: nothing goes yet.
    $purge->purge(now()->toDateTimeImmutable());
    expect(Category::query()->find($used->id))->not->toBeNull();

    // The owner is gone, but an entry still uses the category.
    DB::table('users')->where('id', $this->d->id)->update(['state' => 'purged', 'purged_at' => now(), 'email_ciphertext' => null, 'email_lookup' => null, 'password_hash' => null]);
    expect(app(Accounts::class)->departedAmong([$this->d->id]))->toBe([$this->d->id]);
    $purge->purge(now()->toDateTimeImmutable());
    expect(Category::query()->find($used->id))->not->toBeNull();

    // Nothing uses it any more: the sweep deletes it.
    DB::table('postings')->where('transaction_id', $entry->id)->delete();
    DB::table('transactions')->where('id', $entry->id)->delete();
    DB::table('category_references')->where('category_id', $used->id)->delete();
    $removed = $purge->purge(now()->toDateTimeImmutable());

    expect($removed['departed_categories'])->toBe(1)->and(Category::query()->find($used->id))->toBeNull();
});

test('a category of a person still known is never swept', function () {
    $mine = ownedCategory($this->d->id, ['name' => 'Mine', 'archived_at' => now()]);

    app(TombstonePurge::class)->purge(now()->toDateTimeImmutable());

    expect(Category::query()->find($mine->id))->not->toBeNull()
        ->and(app(Accounts::class)->departedAmong([$this->d->id, (string) Str::uuid7()]))->toHaveCount(1);
});

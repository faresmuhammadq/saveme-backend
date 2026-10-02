<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Categories\CategoryQueryService;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\Categories\Dto\CategoryView;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryFieldImmutable;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryNotFound;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryVersionConflict;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Saving a category (spec 011, US2; FR-001 to FR-006, P-07, ADR-0008 rule 10).
 */

function saveCategory(string $userId, string $categoryId, array $changes, int $version): CategoryView
{
    return app(CategoryService::class)->save($userId, $categoryId, categoryValues($changes), $version, presentCategory())->view;
}

beforeEach(function () {
    $this->owner = User::factory()->create()->id;
    $this->id = (string) Str::uuid7();
});

test('a create sets the owner, version 1 and a change_seq from the owner\'s user counter', function () {
    $nextBefore = (int) DB::scalar('select change_seq from users where id = ?', [$this->owner]);

    $saved = app(CategoryService::class)->save($this->owner, $this->id, categoryValues(), 0, presentCategory());

    $category = Category::query()->findOrFail($this->id);
    expect($saved->created)->toBeTrue()
        ->and($category->user_id)->toBe($this->owner)
        ->and($category->version)->toBe(1)
        ->and($category->change_seq)->toBe($nextBefore + 1)
        ->and((int) DB::scalar('select change_seq from users where id = ?', [$this->owner]))->toBe($category->change_seq)
        ->and($category->system_key)->toBeNull()
        ->and($saved->view->inUse)->toBeFalse();
});

test('a replay with version 0 or the current version changes neither version nor change_seq', function (int $version) {
    saveCategory($this->owner, $this->id, [], 0);
    $before = Category::query()->findOrFail($this->id);

    $saved = app(CategoryService::class)->save($this->owner, $this->id, categoryValues(), $version, presentCategory());

    $after = Category::query()->findOrFail($this->id);
    expect($saved->created)->toBeFalse()
        ->and($after->version)->toBe($before->version)
        ->and($after->change_seq)->toBe($before->change_seq)
        ->and($after->updated_at->equalTo($before->updated_at))->toBeTrue();
})->with([0, 1]);

test('a save bumps the version and takes a new change_seq', function () {
    saveCategory($this->owner, $this->id, [], 0);
    $before = Category::query()->findOrFail($this->id);

    $view = saveCategory($this->owner, $this->id, ['name' => 'Tea', 'color' => null], 1);

    expect($view->category->version)->toBe(2)
        ->and($view->category->changeSeq)->toBeGreaterThan($before->change_seq)
        ->and($view->category->name)->toBe('Tea')
        ->and($view->category->color)->toBeNull();
});

test('the name is stored trimmed', function () {
    $view = saveCategory($this->owner, $this->id, ['name' => '  Coffee  '], 0);

    expect($view->category->name)->toBe('Coffee');
});

test('a stale version is refused with the stored category and nothing is written', function () {
    saveCategory($this->owner, $this->id, [], 0);
    saveCategory($this->owner, $this->id, ['name' => 'Renamed'], 1);

    try {
        saveCategory($this->owner, $this->id, ['name' => 'Other'], 1);
        $this->fail('expected a conflict');
    } catch (CategoryVersionConflict $conflict) {
        $read = app(CategoryQueryService::class)->find($this->owner, $this->id);

        expect($conflict->errorCode)->toBe(ErrorCode::VersionConflict)
            ->and($conflict->extensions['current'])->toBe(presentCategory()($read))
            ->and($conflict->extensions['current']['name'])->toBe('Renamed')
            ->and(Category::query()->find($this->id)->version)->toBe(2);
    }
});

test('a tombstone is a conflict carrying the tombstone, even for an identical body', function () {
    ownedCategory($this->owner, ['id' => $this->id, 'name' => 'Coffee', 'icon' => 'cup', 'color' => '#6D4C41', 'deleted_at' => now()]);

    try {
        saveCategory($this->owner, $this->id, [], 1);
        $this->fail('expected a conflict');
    } catch (CategoryVersionConflict $conflict) {
        expect($conflict->extensions['current']['deleted_at'])->not->toBeNull();
    }
});

test('a kind change is refused at /kind', function () {
    saveCategory($this->owner, $this->id, [], 0);

    expect(fn () => saveCategory($this->owner, $this->id, ['kind' => 'income'], 1))->toThrow(CategoryFieldImmutable::class);
    expect(Category::query()->find($this->id)->kind)->toBe('expense');
});

test('a stale version outranks the kind rule', function () {
    saveCategory($this->owner, $this->id, [], 0);

    expect(fn () => saveCategory($this->owner, $this->id, ['kind' => 'income'], 7))->toThrow(CategoryVersionConflict::class);
});

test('a version above 0 for an unknown id is not found and creates nothing', function () {
    expect(fn () => saveCategory($this->owner, $this->id, [], 3))->toThrow(CategoryNotFound::class);
    expect(Category::query()->count())->toBe(Category::query()->whereNull('user_id')->count());
});

test('an id held by another person, the platform or a wallet is not found and nothing is overwritten', function () {
    $other = ownedCategory((string) Str::uuid7(), ['name' => 'Hers']);
    $platform = Category::factory()->platform()->create();
    $wallet = ownedWallet($this->owner);

    foreach ([$other->id, $platform->id, $wallet->id] as $held) {
        expect(fn () => saveCategory($this->owner, $held, [], 0))->toThrow(CategoryNotFound::class);
    }

    expect(Category::query()->find($other->id)->name)->toBe('Hers')
        ->and(Category::query()->find($platform->id)->name)->toBeNull();
});

test('a patch changes only the members sent', function () {
    saveCategory($this->owner, $this->id, [], 0);

    $view = app(CategoryService::class)->patch($this->owner, $this->id, ['name' => ' Tea '], 1, presentCategory());

    expect($view->category->name)->toBe('Tea')
        ->and($view->category->icon)->toBe('cup')
        ->and($view->category->version)->toBe(2);
});

test('a patch never creates', function () {
    expect(fn () => app(CategoryService::class)->patch($this->owner, $this->id, ['name' => 'X'], 1, presentCategory()))->toThrow(CategoryNotFound::class);
});

test('no domain event is dispatched (P-11)', function () {
    $dispatched = [];
    Event::listen('*', function (string $name) use (&$dispatched): void {
        if (str_starts_with($name, 'App\\Modules\\')) {
            $dispatched[] = $name;
        }
    });

    saveCategory($this->owner, $this->id, [], 0);
    saveCategory($this->owner, $this->id, ['name' => 'Tea'], 1);
    app(CategoryService::class)->archive($this->owner, $this->id, 2, presentCategory());

    expect($dispatched)->toBe([]);
});

<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Categories\CategoryQueryService;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryInUse;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryNotFound;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryVersionConflict;
use App\Modules\Wallets\Infrastructure\Models\Category;
use Illuminate\Support\Str;

/*
 * Archiving and deleting a category (spec 011, US3; FR-007 to FR-010, P-07 rule 5).
 */

beforeEach(function () {
    $this->owner = User::factory()->create()->id;
    $this->category = ownedCategory($this->owner);
});

function deleteCategory(string $userId, string $categoryId, int $version): mixed
{
    return app(CategoryService::class)->delete($userId, $categoryId, $version, presentCategory());
}

function archiveCategory(string $userId, string $categoryId, int $version): mixed
{
    return app(CategoryService::class)->archive($userId, $categoryId, $version, presentCategory());
}

test('deleting tombstones the category: the row stays, version and change_seq move', function () {
    $before = Category::query()->findOrFail($this->category->id);

    $view = deleteCategory($this->owner, $this->category->id, 1);

    $after = Category::query()->findOrFail($this->category->id);
    expect($view->category->deletedAt)->not->toBeNull()
        ->and($after->deleted_at)->not->toBeNull()
        ->and($after->version)->toBe(2)
        ->and($after->change_seq)->toBeGreaterThan($before->change_seq);
});

test('deleting a tombstone returns it unchanged whatever version is sent', function () {
    deleteCategory($this->owner, $this->category->id, 1);
    $tombstone = Category::query()->findOrFail($this->category->id);

    $view = deleteCategory($this->owner, $this->category->id, 99);

    expect($view->category->version)->toBe($tombstone->version)
        ->and(Category::query()->findOrFail($this->category->id)->change_seq)->toBe($tombstone->change_seq);
});

test('a stale version on a live category is a conflict', function () {
    expect(fn () => deleteCategory($this->owner, $this->category->id, 5))->toThrow(CategoryVersionConflict::class);
    expect(Category::query()->findOrFail($this->category->id)->deleted_at)->toBeNull();
});

test('a used category is refused, nothing changes, archive still works and in_use shows', function () {
    fakeCategoryUsage([$this->category->id]);

    expect(fn () => deleteCategory($this->owner, $this->category->id, 1))->toThrow(CategoryInUse::class);

    $unchanged = Category::query()->findOrFail($this->category->id);
    expect($unchanged->deleted_at)->toBeNull()->and($unchanged->version)->toBe(1);

    $archived = archiveCategory($this->owner, $this->category->id, 1);
    expect($archived->category->archivedAt)->not->toBeNull()
        ->and($archived->inUse)->toBeTrue()
        ->and(app(CategoryQueryService::class)->find($this->owner, $this->category->id)->inUse)->toBeTrue();
});

test('usage is re-checked after the row is locked: a used category with a stale view is still refused', function () {
    deleteCategory($this->owner, ownedCategory($this->owner)->id, 1);
    fakeCategoryUsage([$this->category->id]);

    expect(fn () => deleteCategory($this->owner, $this->category->id, 1))->toThrow(CategoryInUse::class);
});

test('archiving sets archived_at once and is idempotent', function () {
    $first = archiveCategory($this->owner, $this->category->id, 1);
    $again = archiveCategory($this->owner, $this->category->id, 1);

    expect($first->category->version)->toBe(2)
        ->and($again->category->version)->toBe(2)
        ->and($again->category->archivedAt)->toEqual($first->category->archivedAt)
        ->and($again->category->changeSeq)->toBe($first->category->changeSeq);
});

test('archiving with a stale version, or a tombstone, is a conflict', function () {
    expect(fn () => archiveCategory($this->owner, $this->category->id, 4))->toThrow(CategoryVersionConflict::class);

    deleteCategory($this->owner, $this->category->id, 1);
    expect(fn () => archiveCategory($this->owner, $this->category->id, 2))->toThrow(CategoryVersionConflict::class);
});

test("another person's category is not found", function () {
    expect(fn () => deleteCategory((string) Str::uuid7(), $this->category->id, 1))->toThrow(CategoryNotFound::class);
    expect(fn () => archiveCategory((string) Str::uuid7(), $this->category->id, 1))->toThrow(CategoryNotFound::class);
});

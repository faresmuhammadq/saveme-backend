<?php

use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetQueryService;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetActionForbidden;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetStateInvalid;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetVersionConflict;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetLineResource;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Categories\CategoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Shared Budget writes (ADR-0027 rules 1 to 3, 8, 11 to 13, 15; P-07): who may
 * act, what stays immutable, and what a limit's history keeps.
 */

beforeEach(function () {
    $this->owner = User::factory()->create()->id;
    $this->partner = User::factory()->create()->id;
    $this->service = app(SharedBudgetService::class);
    $this->present = SharedBudgetResource::toArray(...);
    $this->presentLine = SharedBudgetLineResource::toArray(...);
});

test('creating makes the caller the owner and stamps every row from the budget\'s own scope', function () {
    $userCounter = (int) DB::scalar('select change_seq from users where id = ?', [$this->owner]);

    $id = createSharedBudget($this->owner);

    $budget = app(SharedBudgetRepository::class)->find($id);
    $member = app(SharedBudgetRepository::class)->activeMember($id, $this->owner);
    expect($budget->version)->toBe(1)
        ->and($member->isOwner())->toBeTrue()
        ->and($member->changeSeq)->toBeLessThan($budget->changeSeq)
        ->and($member->memberChangeSeq)->toBe($userCounter + 1);
});

test('a replay changes nothing', function () {
    $id = createSharedBudget($this->owner);
    $before = app(SharedBudgetRepository::class)->find($id);

    $saved = $this->service->save($this->owner, $id, sharedBudgetValues(), 0, $this->present);

    expect($saved->created)->toBeFalse()
        ->and($saved->budget->version)->toBe($before->version)
        ->and($saved->budget->changeSeq)->toBe($before->changeSeq);
});

test('a stale version is refused and the currency never changes', function () {
    $id = createSharedBudget($this->owner);

    expect(fn () => $this->service->save($this->owner, $id, sharedBudgetValues(['name' => 'House']), 9, $this->present))
        ->toThrow(SharedBudgetVersionConflict::class)
        ->and(fn () => $this->service->save($this->owner, $id, sharedBudgetValues(['currency' => 'USD']), 1, $this->present))
        ->toThrow(SharedBudgetStateInvalid::class);
});

test('a non-member gets not-found and a member cannot change the budget', function () {
    $id = createSharedBudget($this->owner);
    $stranger = User::factory()->create()->id;
    joinSharedBudget($id, $this->partner);

    expect(fn () => app(SharedBudgetQueryService::class)->find($stranger, $id))->toThrow(SharedBudgetNotFound::class)
        ->and(fn () => $this->service->patch($stranger, $id, ['name' => 'X'], 1, $this->present))->toThrow(SharedBudgetNotFound::class)
        ->and(fn () => $this->service->patch($this->partner, $id, ['limit_minor' => 5], 1, $this->present))->toThrow(SharedBudgetActionForbidden::class)
        ->and(fn () => $this->service->saveLine($this->partner, $id, (string) Str::uuid7(), ['category_id' => platformCategoryId(), 'limit_minor' => null], 0, $this->presentLine))->toThrow(SharedBudgetActionForbidden::class);

    expect(app(SharedBudgetQueryService::class)->find($this->partner, $id)->id)->toBe($id);
});

test('a limit change keeps the limit earlier periods had', function () {
    $this->travelTo('2026-10-15 12:00:00');
    $id = createSharedBudget($this->owner, ['limit_minor' => 1000]);

    $this->travelTo('2026-11-15 12:00:00');
    $this->service->patch($this->owner, $id, ['limit_minor' => 2000], 1, $this->present);

    $history = app(SharedBudgetRepository::class)->limitHistory($id);
    expect($history)->toHaveCount(2)
        ->and($history[0])->toMatchArray(['limit_minor' => 1000, 'applies_from' => '2026-10-01'])
        ->and($history[1])->toMatchArray(['limit_minor' => 2000, 'applies_from' => '2026-11-01']);
});

test('a start day change applies from the next period', function () {
    $this->travelTo('2026-10-15 12:00:00');
    $id = createSharedBudget($this->owner);

    $this->service->patch($this->owner, $id, ['start_day' => 25], 1, $this->present);

    expect(app(SharedBudgetRepository::class)->startDayHistory($id))->toBe([
        ['start_day' => 1, 'applies_from' => null],
        ['start_day' => 25, 'applies_from' => '2026-11-01'],
    ]);
});

test('a line names one category once, of the owner or the platform, and that category is then in use', function () {
    $id = createSharedBudget($this->owner);
    $lineId = (string) Str::uuid7();
    $values = ['category_id' => platformCategoryId(), 'limit_minor' => 500];

    expect($this->service->saveLine($this->owner, $id, $lineId, $values, 0, $this->presentLine)->created)->toBeTrue()
        ->and(fn () => $this->service->saveLine($this->owner, $id, (string) Str::uuid7(), $values, 0, $this->presentLine))->toThrow(SharedBudgetStateInvalid::class)
        ->and(fn () => $this->service->saveLine($this->owner, $id, (string) Str::uuid7(), ['category_id' => (string) Str::uuid7(), 'limit_minor' => null], 0, $this->presentLine))->toThrow(SharedBudgetStateInvalid::class)
        ->and(app(SharedBudgetRepository::class)->categoryUsed(platformCategoryId()))->toBeTrue();
});

test('the owner cannot leave, and a member can', function () {
    $id = createSharedBudget($this->owner);
    joinSharedBudget($id, $this->partner);

    expect(fn () => $this->service->leave($this->owner, $id))->toThrow(SharedBudgetActionForbidden::class);

    $this->service->leave($this->partner, $id);

    expect(fn () => app(SharedBudgetQueryService::class)->find($this->partner, $id))->toThrow(SharedBudgetNotFound::class);
});

test('transferring ownership moves the role and the old owner stays a member', function () {
    $id = createSharedBudget($this->owner);
    joinSharedBudget($id, $this->partner);

    $budget = $this->service->transferOwnership($this->owner, $id, $this->partner, 1, $this->present);

    $repository = app(SharedBudgetRepository::class);
    expect($budget->ownerId)->toBe($this->partner)
        ->and($repository->activeMember($id, $this->partner)->isOwner())->toBeTrue()
        ->and($repository->activeMember($id, $this->owner)->isOwner())->toBeFalse();
});

test('deleting ends every membership', function () {
    $id = createSharedBudget($this->owner);
    joinSharedBudget($id, $this->partner);

    $budget = $this->service->delete($this->owner, $id, 1, $this->present);

    expect($budget->isTombstoned())->toBeTrue()
        ->and(app(SharedBudgetRepository::class)->activeMembers($id))->toBe([]);
});

test('an account purge passes an owned budget on, and deletes one with nobody left', function () {
    $shared = createSharedBudget($this->owner);
    joinSharedBudget($shared, $this->partner);
    $alone = createSharedBudget($this->owner);

    expect($this->service->removeDepartedUser($this->owner))->toBe(2);

    $repository = app(SharedBudgetRepository::class);
    expect($repository->find($shared)->ownerId)->toBe($this->partner)
        ->and($repository->activeMember($shared, $this->owner))->toBeNull()
        ->and($repository->find($alone)->isTombstoned())->toBeTrue();
});

test('renaming a category carries its new name to every line that names it', function () {
    $id = createSharedBudget($this->owner);
    $category = ownedCategory($this->owner, ['kind' => 'expense', 'name' => 'Old']);
    $this->service->saveLine($this->owner, $id, (string) Str::uuid7(), ['category_id' => $category->id, 'limit_minor' => null], 0, $this->presentLine);

    app(CategoryService::class)->save($this->owner, $category->id, ['kind' => 'expense', 'name' => 'New', 'icon' => $category->icon, 'color' => $category->color], $category->version, presentCategory());

    $line = app(SharedBudgetRepository::class)->liveLines($id)[0];
    expect($line->name)->toBe('New');
});

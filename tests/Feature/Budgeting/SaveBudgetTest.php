<?php

use App\Modules\Budgeting\Application\BudgetService;
use App\Modules\Budgeting\Domain\Refusals\BudgetNotFound;
use App\Modules\Budgeting\Domain\Refusals\BudgetStateInvalid;
use App\Modules\Budgeting\Domain\Refusals\BudgetVersionConflict;
use App\Modules\Budgeting\Infrastructure\Models\Budget;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
 * Saving a budget (spec 015, US1 and US4; FR-001, FR-002, FR-005, FR-006,
 * FR-022; P-07).
 */

function userCounterOf(string $userId): int
{
    return (int) DB::scalar('select change_seq from users where id = ?', [$userId]);
}

beforeEach(function () {
    $this->owner = User::factory()->create()->id;
    $this->id = (string) Str::uuid7();
});

test('a create sets the owner, version 1 and a change_seq from the owner\'s user counter', function () {
    $before = userCounterOf($this->owner);

    $saved = saveBudget($this->owner, $this->id, budgetBody());

    $budget = Budget::query()->findOrFail($this->id);
    expect($saved->created)->toBeTrue()
        ->and($budget->user_id)->toBe($this->owner)
        ->and($budget->version)->toBe(1)
        ->and($budget->change_seq)->toBe($before + 1)
        ->and(userCounterOf($this->owner))->toBe($before + 1)
        ->and($budget->archived_at)->toBeNull()
        ->and($budget->amount_minor)->toBe(600000)
        ->and($budget->currency)->toBe('EGP');
});

test('a replay with version 0 or the current version moves nothing', function (int $version) {
    saveBudget($this->owner, $this->id, budgetBody());
    $before = Budget::query()->findOrFail($this->id);
    $counter = userCounterOf($this->owner);

    $saved = saveBudget($this->owner, $this->id, budgetBody(version: $version));

    $after = Budget::query()->findOrFail($this->id);
    expect($saved->created)->toBeFalse()
        ->and($after->version)->toBe($before->version)
        ->and($after->change_seq)->toBe($before->change_seq)
        ->and($after->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and(userCounterOf($this->owner))->toBe($counter)
        ->and(Budget::query()->count())->toBe(1);
})->with([0, 1]);

test('an all-expenses budget has no category', function () {
    $saved = saveBudget($this->owner, $this->id, budgetBody(['category_id' => null]));

    expect($saved->budget->categoryId)->toBeNull();
});

test('a version above 0 for an unknown id is not found', function () {
    saveBudget($this->owner, $this->id, budgetBody(version: 3));
})->throws(BudgetNotFound::class);

test('another person\'s budget id is not found, whatever the version', function (int $version) {
    $other = User::factory()->create()->id;
    $theirs = ownedBudget($other);

    expect(refusalOf(fn () => saveBudget($this->owner, $theirs->id, budgetBody(version: $version))))->toBeInstanceOf(BudgetNotFound::class)
        ->and(Budget::query()->findOrFail($theirs->id)->user_id)->toBe($other);
})->with([0, 1]);

test('an id that names another kind of record is not found', function () {
    $wallet = ownedWallet($this->owner);

    expect(refusalOf(fn () => saveBudget($this->owner, $wallet->id, budgetBody())))->toBeInstanceOf(BudgetNotFound::class);
});

test('a budget in a currency the owner holds no wallet in is accepted', function () {
    expect(saveBudget($this->owner, $this->id, budgetBody(['currency' => 'USD']))->budget->currency)->toBe('USD');
});

test('budgets on the same category and currency both persist', function () {
    $category = platformCategoryId();

    createBudget($this->owner, ['category_id' => $category]);
    createBudget($this->owner, ['category_id' => $category]);

    expect(Budget::query()->where('user_id', $this->owner)->where('category_id', $category)->count())->toBe(2);
});

test('no write dispatches a domain event', function () {
    $dispatched = [];
    Event::listen('*', function (string $name) use (&$dispatched): void {
        if (str_starts_with($name, 'App\\Modules\\')) {
            $dispatched[] = $name;
        }
    });

    $id = createBudget($this->owner)->id;
    app(BudgetService::class)->patch($this->owner, $id, 700000, 1, presentBudget());
    app(BudgetService::class)->archive($this->owner, $id, 2, presentBudget());
    app(BudgetService::class)->delete($this->owner, $id, 3, presentBudget());

    expect($dispatched)->toBe([]);
});

test('a whole save and a patch bump the version and take new stamps', function () {
    $created = saveBudget($this->owner, $this->id, budgetBody())->budget;

    $saved = saveBudget($this->owner, $this->id, budgetBody(['amount_minor' => 700000], version: 1))->budget;
    $patched = app(BudgetService::class)->patch($this->owner, $this->id, 800000, 2, presentBudget());

    expect($saved->version)->toBe(2)->and($saved->amountMinor)->toBe(700000)->and($saved->changeSeq)->toBeGreaterThan($created->changeSeq)
        ->and($patched->version)->toBe(3)->and($patched->amountMinor)->toBe(800000)->and($patched->changeSeq)->toBeGreaterThan($saved->changeSeq);
});

test('a patch that changes nothing is a replay', function () {
    $budget = createBudget($this->owner);
    $counter = userCounterOf($this->owner);

    $same = app(BudgetService::class)->patch($this->owner, $budget->id, $budget->amountMinor, 1, presentBudget());

    expect($same->version)->toBe(1)->and(userCounterOf($this->owner))->toBe($counter);
});

test('a patch never creates', function () {
    app(BudgetService::class)->patch($this->owner, $this->id, 1000, 1, presentBudget());
})->throws(BudgetNotFound::class);

test('a stale version is a conflict carrying the stored budget, and nothing moves', function () {
    $budget = createBudget($this->owner);
    saveBudget($this->owner, $budget->id, budgetBody(['amount_minor' => 700000], version: 1));
    $counter = userCounterOf($this->owner);

    $refusal = refusalOf(fn () => saveBudget($this->owner, $budget->id, budgetBody(['amount_minor' => 900000], version: 1)));

    expect($refusal)->toBeInstanceOf(BudgetVersionConflict::class)
        ->and($refusal->extensions['current']['amount_minor'])->toBe(700000)
        ->and($refusal->extensions['current']['version'])->toBe(2)
        ->and(userCounterOf($this->owner))->toBe($counter)
        ->and(Budget::query()->findOrFail($budget->id)->amount_minor)->toBe(700000);
});

test('an identical write to a tombstone is a conflict, never a replay', function () {
    $budget = createBudget($this->owner);
    app(BudgetService::class)->delete($this->owner, $budget->id, 1, presentBudget());

    $refusal = refusalOf(fn () => saveBudget($this->owner, $budget->id, budgetBody(['category_id' => $budget->categoryId], version: 2)));

    expect($refusal)->toBeInstanceOf(BudgetVersionConflict::class)
        ->and($refusal->extensions['current']['deleted_at'])->not->toBeNull();

    expect(refusalOf(fn () => app(BudgetService::class)->patch($this->owner, $budget->id, 9, 2, presentBudget())))->toBeInstanceOf(BudgetVersionConflict::class)
        ->and(refusalOf(fn () => app(BudgetService::class)->archive($this->owner, $budget->id, 2, presentBudget())))->toBeInstanceOf(BudgetVersionConflict::class);
});

test('changed immutable members are refused together, even with the right version', function () {
    $budget = createBudget($this->owner);

    $refusal = refusalOf(fn () => saveBudget($this->owner, $budget->id, budgetBody(['category_id' => null, 'currency' => 'USD', 'period_type' => 'monthly'], version: 1)));

    expect($refusal)->toBeInstanceOf(BudgetStateInvalid::class)
        ->and(fieldErrorsOf($refusal))->toBe(['/category_id IMMUTABLE_FIELD', '/currency IMMUTABLE_FIELD']);
});

test('the immutability rules do not outrank a stale version', function () {
    $budget = createBudget($this->owner);
    saveBudget($this->owner, $budget->id, budgetBody(['amount_minor' => 700000], version: 1));

    expect(refusalOf(fn () => saveBudget($this->owner, $budget->id, budgetBody(['currency' => 'USD'], version: 1))))->toBeInstanceOf(BudgetVersionConflict::class);
});

test('archive sets archived_at once; a repeat changes nothing; the limit stays editable', function () {
    $budget = createBudget($this->owner);

    $archived = app(BudgetService::class)->archive($this->owner, $budget->id, 1, presentBudget());
    $counter = userCounterOf($this->owner);
    $again = app(BudgetService::class)->archive($this->owner, $budget->id, 1, presentBudget());

    expect($archived->archivedAt)->not->toBeNull()->and($archived->version)->toBe(2)
        ->and($again->version)->toBe(2)->and($again->changeSeq)->toBe($archived->changeSeq)
        ->and(userCounterOf($this->owner))->toBe($counter);

    $edited = saveBudget($this->owner, $budget->id, budgetBody(['category_id' => $budget->categoryId, 'amount_minor' => 650000], version: 2))->budget;
    expect($edited->amountMinor)->toBe(650000)->and($edited->archivedAt)->not->toBeNull();
});

test('archiving with a stale version is a conflict', function () {
    $budget = createBudget($this->owner);
    saveBudget($this->owner, $budget->id, budgetBody(['category_id' => $budget->categoryId, 'amount_minor' => 700000], version: 1));

    expect(refusalOf(fn () => app(BudgetService::class)->archive($this->owner, $budget->id, 1, presentBudget())))->toBeInstanceOf(BudgetVersionConflict::class);
});

test('delete tombstones; a repeat returns the tombstone whatever the version; there is no restore', function () {
    $budget = createBudget($this->owner);

    $deleted = app(BudgetService::class)->delete($this->owner, $budget->id, 1, presentBudget());
    $counter = userCounterOf($this->owner);
    $again = app(BudgetService::class)->delete($this->owner, $budget->id, 99, presentBudget());

    expect($deleted->deletedAt)->not->toBeNull()->and($deleted->version)->toBe(2)
        ->and($again->version)->toBe(2)->and($again->deletedAt)->not->toBeNull()
        ->and(userCounterOf($this->owner))->toBe($counter)
        ->and(Budget::query()->findOrFail($budget->id)->deleted_at)->not->toBeNull();
});

test('deleting with a stale version is a conflict and deleting an unknown id is not found', function () {
    $budget = createBudget($this->owner);

    expect(refusalOf(fn () => app(BudgetService::class)->delete($this->owner, $budget->id, 5, presentBudget())))->toBeInstanceOf(BudgetVersionConflict::class)
        ->and(refusalOf(fn () => app(BudgetService::class)->delete($this->owner, (string) Str::uuid7(), 1, presentBudget())))->toBeInstanceOf(BudgetNotFound::class);
});

test('every write stamps the owner\'s counter without a hole, and a rolled-back conflict leaves none', function () {
    $start = userCounterOf($this->owner);
    $budget = createBudget($this->owner);

    refusalOf(fn () => saveBudget($this->owner, $budget->id, budgetBody(['category_id' => $budget->categoryId, 'amount_minor' => 5], version: 7)));
    app(BudgetService::class)->patch($this->owner, $budget->id, 700000, 1, presentBudget());
    refusalOf(fn () => app(BudgetService::class)->patch($this->owner, $budget->id, 800000, 1, presentBudget()));
    app(BudgetService::class)->archive($this->owner, $budget->id, 2, presentBudget());
    app(BudgetService::class)->delete($this->owner, $budget->id, 3, presentBudget());

    expect(userCounterOf($this->owner))->toBe($start + 4)
        ->and(Budget::query()->findOrFail($budget->id)->change_seq)->toBe($start + 4);
});

<?php

use App\Modules\Wallets\Application\Categories\CategoryQueryService;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryInUse;
use Illuminate\Support\Facades\DB;

/*
 * "In use" is answered for rules and occurrences too (spec 014, R-7, FR-027):
 * a category a rule (category or payout category) or an occurrence references,
 * live or tombstoned, is in use, set-wise, and deleting it is CATEGORY_IN_USE.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
    $this->spare = ownedCategory($this->owner->id, ['name' => 'Spare']);
});

it('reports the category of a rule in use', function () {
    $category = ownedCategory($this->owner->id, ['name' => 'Rent']);
    createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['category_id' => $category->id]));
    $find = fn (string $id) => app(CategoryQueryService::class)->find($this->owner->id, $id)->inUse;

    expect($find($category->id))->toBeTrue()->and($find($this->spare->id))->toBeFalse();
});

it('reports a gam\'eya\'s payout category in use', function () {
    $payout = ownedCategory($this->owner->id, ['name' => 'Payout', 'kind' => 'income']);
    createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['preset' => 'gameya', 'amount_minor' => 1000, 'occurrence_count' => 5, 'payout_round' => 2, 'payout_category_id' => $payout->id]));

    expect(app(CategoryQueryService::class)->find($this->owner->id, $payout->id)->inUse)->toBeTrue();
});

it('reports the category of an edited occurrence in use, a tombstoned rule still counting', function () {
    $edited = ownedCategory($this->owner->id, ['name' => 'Edited', 'kind' => 'expense']);
    $rule = createRecurringRule($this->owner->id, $this->wallet->id)->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $occurrence->id, occurrenceBody($occurrence, ['category_id' => $edited->id]));
    app(RecurringRuleService::class)->delete($this->owner->id, $this->wallet->id, $rule->id, 1, presentRule());

    expect(app(CategoryQueryService::class)->find($this->owner->id, $edited->id)->inUse)->toBeTrue()
        ->and(refusalOf(fn () => app(CategoryService::class)->delete($this->owner->id, $edited->id, 1, presentCategory())))->toBeInstanceOf(CategoryInUse::class);
});

it('lets an unused category be deleted', function () {
    expect(app(CategoryService::class)->delete($this->owner->id, $this->spare->id, 1, presentCategory())->category->isTombstone())->toBeTrue();
});

it('answers a page of 200 categories in a constant number of queries against the recurring tables', function () {
    $count = function (): int {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        app(CategoryQueryService::class)->listFor($this->owner->id, 'name', false, null, 200);

        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'recurring_rules') || str_contains($sql, 'occurrences')));
    };

    $few = $count();
    foreach (range(1, 60) as $index) {
        ownedCategory($this->owner->id, ['name' => 'c'.$index]);
    }
    createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['category_id' => $this->spare->id]));

    expect($count())->toBe($few)->and($few)->toBe(2);
});

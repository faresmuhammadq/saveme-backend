<?php

use App\Modules\Wallets\Application\Categories\CategoryQueryService;
use App\Modules\Wallets\Application\Categories\CategoryService;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Categories\Refusals\CategoryInUse;
use Illuminate\Support\Facades\DB;

/*
 * "In use" is answered by the ledger (spec 012, US5 S2, R-8; spec 011 FR-008):
 * live and tombstoned transactions count, one set-wise query per page.
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id);
    $this->category = ownedCategory($this->owner->id, ['name' => 'Coffee']);
    $this->spare = ownedCategory($this->owner->id, ['name' => 'Spare']);
});

test('a category of a live transaction is in use', function () {
    recordTransaction($this->owner->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));

    $find = fn ($id) => app(CategoryQueryService::class)->find($this->owner->id, $id)->inUse;

    expect($find($this->category->id))->toBeTrue()->and($find($this->spare->id))->toBeFalse();
});

test('a tombstoned transaction still counts, and deleting the category is CATEGORY_IN_USE', function () {
    $view = recordTransaction($this->owner->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));
    app(TransactionService::class)->delete($this->owner->id, $this->wallet->id, $view->transaction->id, 1, presentTransaction());

    expect(app(CategoryQueryService::class)->find($this->owner->id, $this->category->id)->inUse)->toBeTrue()
        ->and(refusalOf(fn () => app(CategoryService::class)->delete($this->owner->id, $this->category->id, 1, presentCategory())))->toBeInstanceOf(CategoryInUse::class);
});

test('an unused category deletes, and a platform category is never asked about by a person', function () {
    expect(app(CategoryService::class)->delete($this->owner->id, $this->spare->id, 1, presentCategory())->category->isTombstone())->toBeTrue();
});

test('a page of 200 categories costs a constant number of queries', function () {
    $count = function (): int {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        app(CategoryQueryService::class)->listFor($this->owner->id, 'name', false, null, 200);

        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, '"transactions"')));
    };

    $few = $count();
    foreach (range(1, 60) as $index) {
        ownedCategory($this->owner->id, ['name' => 'c'.$index]);
    }
    recordTransaction($this->owner->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));

    expect($count())->toBe($few)->and($few)->toBe(1);
});

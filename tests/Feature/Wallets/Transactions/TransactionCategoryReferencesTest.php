<?php

use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;

/*
 * Category references from transactions (spec 012, US1 S7, US5 S4, FR-022,
 * R-8; spec 011 FR-021): published once per (wallet, person's category), none
 * for a platform category, kept while any live or tombstoned transaction in
 * the wallet uses the category, withdrawn on the last edit away.
 */

beforeEach(function () {
    $this->owner = person();
    $this->editor = person('grace@example.test');
    $this->wallet = ownedWallet($this->owner->id);
    addMember($this->wallet->id, $this->editor->id, 'editor');
    $this->category = ownedCategory($this->editor->id, ['name' => 'Coffee']);
    $this->other = ownedCategory($this->editor->id, ['name' => 'Tea']);
    $this->service = app(TransactionService::class);
});

test('a person\'s category is published into the wallet once, however many transactions use it', function () {
    recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));
    recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));

    $reference = CategoryReference::query()->where('wallet_id', $this->wallet->id)->sole();
    expect($reference->category_id)->toBe($this->category->id)->and($reference->deleted_at)->toBeNull()->and($reference->name)->toBe('Coffee');
    expectTransactionScopeIntact($this->wallet->id);
});

test('a platform category publishes nothing', function () {
    recordTransaction($this->owner->id, $this->wallet->id, transactionBody());

    expect(CategoryReference::query()->count())->toBe(0);
});

test('an edit to another category publishes the new one and withdraws the old when it was the last user', function () {
    $view = recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));

    $this->service->patch($this->editor->id, $this->wallet->id, $view->transaction->id, ['category_id' => $this->other->id], 1, presentTransaction());

    $live = CategoryReference::query()->whereNull('deleted_at')->pluck('category_id')->all();
    expect($live)->toBe([$this->other->id])
        ->and(CategoryReference::query()->whereNotNull('deleted_at')->pluck('category_id')->all())->toBe([$this->category->id]);
    expectTransactionScopeIntact($this->wallet->id);
});

test('the old reference stays while another transaction in the wallet still uses the category', function () {
    $first = recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));
    recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));

    $this->service->patch($this->editor->id, $this->wallet->id, $first->transaction->id, ['category_id' => $this->other->id], 1, presentTransaction());

    expect(CategoryReference::query()->whereNull('deleted_at')->pluck('category_id')->sort()->values()->all())->toBe(collect([$this->category->id, $this->other->id])->sort()->values()->all());
});

test('a tombstoned transaction keeps the reference alive, and a delete or restore changes no reference', function () {
    $view = recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));
    $before = CategoryReference::query()->sole()->only(['version', 'change_seq', 'deleted_at']);

    $this->service->delete($this->editor->id, $this->wallet->id, $view->transaction->id, 1, presentTransaction());
    $this->service->restore($this->editor->id, $this->wallet->id, $view->transaction->id, 2, presentTransaction());

    expect(CategoryReference::query()->sole()->only(['version', 'change_seq', 'deleted_at']))->toEqual($before);
});

test('a tombstone using the old category blocks its withdrawal', function () {
    $first = recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));
    $second = recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));
    $this->service->delete($this->editor->id, $this->wallet->id, $second->transaction->id, 1, presentTransaction());

    $this->service->patch($this->editor->id, $this->wallet->id, $first->transaction->id, ['category_id' => $this->other->id], 1, presentTransaction());

    expect(CategoryReference::query()->where('category_id', $this->category->id)->sole()->deleted_at)->toBeNull();
});

test('a withdrawn reference is revived by the next publish', function () {
    $view = recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));
    $this->service->patch($this->editor->id, $this->wallet->id, $view->transaction->id, ['category_id' => $this->other->id], 1, presentTransaction());
    $this->service->patch($this->editor->id, $this->wallet->id, $view->transaction->id, ['category_id' => $this->category->id], 2, presentTransaction());

    $reference = CategoryReference::query()->where('category_id', $this->category->id)->sole();
    expect($reference->deleted_at)->toBeNull()->and(CategoryReference::query()->count())->toBe(2);
});

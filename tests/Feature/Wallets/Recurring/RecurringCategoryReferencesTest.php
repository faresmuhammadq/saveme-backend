<?php

use App\Modules\Wallets\Application\Recurring\RecurringRuleService;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Infrastructure\Models\CategoryReference;

/*
 * Category references and B6's withdrawal (spec 014, R-7, FR-027, A-36): a
 * reference is published for a person's category a rule or occurrence uses
 * and withdrawn only when no transaction, rule or occurrence in the wallet,
 * live or tombstoned, uses it. B6's withdrawal now consults rules.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->editor = $this->world['editor'];
    $this->wallet = $this->world['wallet'];
    $this->category = ownedCategory($this->editor->id, ['name' => 'Coffee']);
    $this->other = ownedCategory($this->editor->id, ['name' => 'Tea']);
});

/**
 * @return list<string>
 */
function liveReferences(string $walletId): array
{
    return CategoryReference::query()->where('wallet_id', $walletId)->whereNull('deleted_at')->pluck('category_id')->all();
}

it('publishes a person\'s category a rule uses so every member can render it', function () {
    createRecurringRule($this->editor->id, $this->wallet->id, recurringRuleBody(['category_id' => $this->category->id]));

    expect(liveReferences($this->wallet->id))->toBe([$this->category->id]);
});

it('keeps a reference while a rule uses the category after the last transaction moved away', function () {
    createRecurringRule($this->editor->id, $this->wallet->id, recurringRuleBody(['category_id' => $this->category->id]));
    $transaction = recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));

    app(TransactionService::class)->patch($this->editor->id, $this->wallet->id, $transaction->transaction->id, ['category_id' => $this->other->id], 1, presentTransaction());

    expect(liveReferences($this->wallet->id))->toContain($this->category->id, $this->other->id);
});

it('keeps a reference while an edited occurrence uses the category', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id)->rule;
    $occurrence = occurrencesOf($rule->id)->first();
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $occurrence->id, occurrenceBody($occurrence, ['category_id' => platformCategoryId()]));
    $transaction = recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));
    $mine = occurrencesOf($rule->id)->first();
    saveRecurringOccurrence($this->editor->id, $this->wallet->id, $mine->id, occurrenceBody($mine, ['category_id' => $this->category->id]));

    app(TransactionService::class)->patch($this->editor->id, $this->wallet->id, $transaction->transaction->id, ['category_id' => $this->other->id], 1, presentTransaction());

    expect(liveReferences($this->wallet->id))->toContain($this->category->id);
});

it('withdraws the reference when nothing in the wallet uses the category any more', function () {
    $rule = createRecurringRule($this->editor->id, $this->wallet->id, recurringRuleBody(['category_id' => $this->category->id]))->rule;
    $transaction = recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));

    saveRecurringRule($this->editor->id, $this->wallet->id, $rule->id, recurringRuleBody(['category_id' => platformCategoryId()], 1));

    expect(liveReferences($this->wallet->id))->toBe([$this->category->id]);

    app(TransactionService::class)->patch($this->editor->id, $this->wallet->id, $transaction->transaction->id, ['category_id' => $this->other->id], 1, presentTransaction());

    expect(liveReferences($this->wallet->id))->toBe([$this->other->id]);
});

it('keeps the reference of a deleted rule\'s category: a tombstone still uses it', function () {
    $rule = createRecurringRule($this->editor->id, $this->wallet->id, recurringRuleBody(['category_id' => $this->category->id]))->rule;
    app(RecurringRuleService::class)->delete($this->editor->id, $this->wallet->id, $rule->id, 1, presentRule());
    $transaction = recordTransaction($this->editor->id, $this->wallet->id, transactionBody(['category_id' => $this->category->id]));

    app(TransactionService::class)->patch($this->editor->id, $this->wallet->id, $transaction->transaction->id, ['category_id' => $this->other->id], 1, presentTransaction());

    expect(liveReferences($this->wallet->id))->toContain($this->category->id);
});

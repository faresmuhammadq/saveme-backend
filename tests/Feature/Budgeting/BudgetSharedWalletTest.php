<?php

use App\Modules\Budgeting\Application\BudgetQueryService;
use App\Modules\Budgeting\Domain\Refusals\BudgetNotFound;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Str;

/*
 * Budgets in a shared wallet stay personal (spec 015, FR-010, US3 S1-S4,
 * ADR-0014 rule 5): spending is attributed by `created_by`, in wallets the
 * owner currently belongs to, so a platform category's total is always one
 * person's.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->editor = $this->world['editor'];
    $this->viewer = $this->world['viewer'];
    $this->wallet = $this->world['wallet'];
    $this->groceries = platformCategoryId();
    $this->dining = nthPlatformCategoryId(1);
    $this->ownerBudget = createBudget($this->owner->id, ['category_id' => $this->groceries]);
    $this->editorBudget = createBudget($this->editor->id, ['category_id' => $this->groceries]);
});

function sharedSpent(object $test, object $user, object $budget): int
{
    return budgetProgress($user->id, $budget->id)->spent->amountMinor;
}

test('two members\' budgets on the same platform category each count only their own expense', function () {
    spend($this->owner->id, $this->wallet->id, 100000);
    spend($this->editor->id, $this->wallet->id, 25000);

    expect(sharedSpent($this, $this->owner, $this->ownerBudget))->toBe(100000)
        ->and(sharedSpent($this, $this->editor, $this->editorBudget))->toBe(25000);
});

test('a partner re-categorising my transaction removes it from my category budget, keeps it in my all-expenses budget, and adds it to none of theirs', function () {
    $mine = spend($this->owner->id, $this->wallet->id, 100000)->transaction;
    $allExpenses = createBudget($this->owner->id, ['category_id' => null]);
    $partnersAll = createBudget($this->editor->id, ['category_id' => null]);

    app(TransactionService::class)->patch($this->editor->id, $this->wallet->id, $mine->id, ['category_id' => $this->dining], 1, presentTransaction());

    expect(sharedSpent($this, $this->owner, $this->ownerBudget))->toBe(0)
        ->and(budgetProgress($this->owner->id, $allExpenses->id)->spent->amountMinor)->toBe(100000)
        ->and(budgetProgress($this->editor->id, $partnersAll->id)->spent->amountMinor)->toBe(0)
        ->and(sharedSpent($this, $this->editor, $this->editorBudget))->toBe(0);
});

test('after I leave the wallet my transactions in it stop counting', function () {
    spend($this->editor->id, $this->wallet->id, 25000);
    expect(sharedSpent($this, $this->editor, $this->editorBudget))->toBe(25000);

    WalletMember::query()->where('wallet_id', $this->wallet->id)->where('user_id', $this->editor->id)->update(['deleted_at' => now()]);

    expect(sharedSpent($this, $this->editor, $this->editorBudget))->toBe(0)
        ->and(sharedSpent($this, $this->owner, $this->ownerBudget))->toBe(0);
});

test('a viewer\'s own earlier transactions still count', function () {
    $viewerBudget = createBudget($this->viewer->id, ['category_id' => $this->groceries]);
    WalletMember::query()->where('wallet_id', $this->wallet->id)->where('user_id', $this->viewer->id)->update(['role' => 'editor']);
    spend($this->viewer->id, $this->wallet->id, 8000);
    WalletMember::query()->where('wallet_id', $this->wallet->id)->where('user_id', $this->viewer->id)->update(['role' => 'viewer']);

    expect(budgetProgress($this->viewer->id, $viewerBudget->id)->spent->amountMinor)->toBe(8000);
});

test('a recurring rule\'s transaction confirmed by another member counts for the rule\'s author', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['category_id' => $this->groceries, 'start_date' => '2026-10-20', 'amount_basis' => 'per_occurrence']))->rule;
    $occurrence = occurrencesOf($rule->id)->first();

    saveRecurringOccurrence($this->editor->id, $this->wallet->id, $occurrence->id, confirmBody($occurrence, ['transaction_id' => (string) Str::uuid7(), 'date' => '2026-10-20']));

    expect(sharedSpent($this, $this->owner, $this->ownerBudget))->toBe(450000)
        ->and(sharedSpent($this, $this->editor, $this->editorBudget))->toBe(0);
});

test('another person\'s budget is not found by id', function () {
    expect(refusalOf(fn () => app(BudgetQueryService::class)->progressOf($this->editor->id, $this->ownerBudget->id, null)))
        ->toBeInstanceOf(BudgetNotFound::class);
});

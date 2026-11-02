<?php

use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetLineResource;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Domain\Transactions\Refusals\TransactionStateInvalid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Linking a transaction to a Shared Budget (ADR-0027 rules 4 to 8, 11): the
 * entry is written with the ledger write, carries no wallet, and follows its
 * transaction until its author leaves.
 */

function sharedExpense(string $budgetId, array $changes = []): array
{
    return transactionBody(['shared_budget_id' => $budgetId, ...$changes]);
}

function refusedAt(Closure $write): array
{
    try {
        $write();
    } catch (TransactionStateInvalid $invalid) {
        return array_map(fn ($error) => [$error->pointer, $error->code->value], $invalid->fieldErrors);
    }

    return [];
}

beforeEach(function () {
    $this->owner = User::factory()->create()->id;
    $this->partner = User::factory()->create()->id;
    $this->budget = createSharedBudget($this->owner);
    joinSharedBudget($this->budget, $this->partner);
    $this->repository = app(SharedBudgetRepository::class);
    $this->ownerWallet = ownedWallet($this->owner);
    $this->partnerWallet = ownedWallet($this->partner);
});

test('a linked expense writes an entry with no wallet in it', function () {
    $view = recordTransaction($this->owner, $this->ownerWallet->id, sharedExpense($this->budget, ['amount_minor' => 12500, 'description' => 'Milk', 'memo' => 'private']));

    $entry = $this->repository->entryFor($this->budget, $view->transaction->id);
    expect($entry->amountMinor)->toBe(12500)
        ->and($entry->createdBy)->toBe($this->owner)
        ->and($entry->description)->toBe('Milk')
        ->and($entry->categoryId)->toBe(platformCategoryId())
        ->and(array_keys((array) DB::table('shared_budget_entries')->first()))->not->toContain('wallet_id', 'memo', 'counterparty')
        ->and($view->transaction->sharedBudgetId)->toBe($this->budget);
});

test('a transaction in a private wallet reaches the budget the partner reads', function () {
    recordTransaction($this->partner, $this->partnerWallet->id, sharedExpense($this->budget, ['amount_minor' => 700]));
    recordTransaction($this->owner, $this->ownerWallet->id, sharedExpense($this->budget, ['amount_minor' => 300]));

    $totals = collect($this->repository->totals($this->budget, '2000-01-01', '2999-12-31'))->sum('amount_minor');
    expect($totals)->toBe(1000);
});

test('editing the amount moves the entry, and clearing the link removes it', function () {
    $view = recordTransaction($this->owner, $this->ownerWallet->id, sharedExpense($this->budget));
    $id = $view->transaction->id;

    $edited = app(TransactionService::class)->patch($this->owner, $this->ownerWallet->id, $id, ['amount_minor' => 999], 1, presentTransaction());
    expect($this->repository->entryFor($this->budget, $id)->amountMinor)->toBe(999);

    app(TransactionService::class)->patch($this->owner, $this->ownerWallet->id, $id, ['shared_budget_id' => null], $edited->transaction->version, presentTransaction());
    expect($this->repository->entryFor($this->budget, $id)->isTombstoned())->toBeTrue()
        ->and($this->repository->totals($this->budget, '2000-01-01', '2999-12-31'))->toBe([]);
});

test('deleting the transaction removes the entry and restoring brings it back', function () {
    $view = recordTransaction($this->owner, $this->ownerWallet->id, sharedExpense($this->budget));
    $id = $view->transaction->id;

    $deleted = app(TransactionService::class)->delete($this->owner, $this->ownerWallet->id, $id, 1, presentTransaction());
    expect($this->repository->entryFor($this->budget, $id)->isTombstoned())->toBeTrue();

    app(TransactionService::class)->restore($this->owner, $this->ownerWallet->id, $id, $deleted->transaction->version, presentTransaction());
    expect($this->repository->entryFor($this->budget, $id)->isTombstoned())->toBeFalse();
});

test('a write that does not mention the link keeps it', function () {
    $view = recordTransaction($this->owner, $this->ownerWallet->id, sharedExpense($this->budget));
    $body = transactionBody(['amount_minor' => 4242], 1);

    $saved = saveTransaction($this->owner, $this->ownerWallet->id, $view->transaction->id, $body);

    expect($saved->transaction->sharedBudgetId)->toBe($this->budget)
        ->and($this->repository->entryFor($this->budget, $view->transaction->id)->amountMinor)->toBe(4242);
});

test('a budget the author may not use is refused at /shared_budget_id', function (string $case) {
    $stranger = User::factory()->create()->id;
    $strangerWallet = ownedWallet($stranger);
    $foreign = createSharedBudget($stranger);
    $body = match ($case) {
        'foreign' => sharedExpense($foreign),
        'unknown' => sharedExpense((string) Str::uuid7()),
        'income' => incomeBody(['shared_budget_id' => $this->budget]),
        'currency' => sharedExpense(createSharedBudget($this->owner, ['currency' => 'USD'])),
    };

    $errors = refusedAt(fn () => recordTransaction($this->owner, $this->ownerWallet->id, $body));

    expect($errors)->toContain($case === 'currency' ? ['/shared_budget_id', 'CURRENCY_MISMATCH'] : ['/shared_budget_id', 'INVALID_ENUM_VALUE']);
})->with(['foreign', 'unknown', 'income', 'currency']);

test('a category no line names and the partner cannot render is refused', function () {
    $custom = ownedCategory($this->owner, ['kind' => 'expense']);

    $errors = refusedAt(fn () => recordTransaction($this->owner, $this->ownerWallet->id, sharedExpense($this->budget, ['category_id' => $custom->id])));

    expect($errors)->toContain(['/shared_budget_id', 'INVALID_ENUM_VALUE']);
});

test('a category the budget lists is usable by a member who does not own it', function () {
    $custom = ownedCategory($this->owner, ['kind' => 'expense']);
    app(SharedBudgetService::class)->saveLine($this->owner, $this->budget, (string) Str::uuid7(), ['category_id' => $custom->id, 'limit_minor' => null], 0, SharedBudgetLineResource::toArray(...));

    $view = recordTransaction($this->partner, $this->partnerWallet->id, sharedExpense($this->budget, ['category_id' => $custom->id]));

    expect($this->repository->entryFor($this->budget, $view->transaction->id)->categoryId)->toBe($custom->id);
});

test('without a budget a partner\'s category is still refused', function () {
    $custom = ownedCategory($this->owner, ['kind' => 'expense']);

    $errors = refusedAt(fn () => recordTransaction($this->partner, $this->partnerWallet->id, transactionBody(['category_id' => $custom->id])));

    expect($errors)->toContain(['/category_id', 'INVALID_ENUM_VALUE']);
});

test('only the author changes the link', function () {
    $shared = ownedWallet($this->owner);
    addMember($shared->id, $this->partner, 'editor');
    $view = recordTransaction($this->owner, $shared->id, transactionBody());

    $errors = refusedAt(fn () => app(TransactionService::class)->patch($this->partner, $shared->id, $view->transaction->id, ['shared_budget_id' => $this->budget], 1, presentTransaction()));

    expect($errors)->toContain(['/shared_budget_id', 'INVALID_ENUM_VALUE']);
});

test('a co-editor changing the amount moves the entry of the author', function () {
    $shared = ownedWallet($this->owner);
    addMember($shared->id, $this->partner, 'editor');
    $view = recordTransaction($this->owner, $shared->id, sharedExpense($this->budget));

    app(TransactionService::class)->patch($this->partner, $shared->id, $view->transaction->id, ['amount_minor' => 5], 1, presentTransaction());

    $entry = $this->repository->entryFor($this->budget, $view->transaction->id);
    expect($entry->amountMinor)->toBe(5)->and($entry->createdBy)->toBe($this->owner);
});

test('a read-only member and an archived budget cannot take new links', function () {
    DB::table('shared_budget_members')->where('user_id', $this->partner)->update(['read_only' => true]);

    expect(refusedAt(fn () => recordTransaction($this->partner, $this->partnerWallet->id, sharedExpense($this->budget))))->toContain(['/shared_budget_id', 'INVALID_ENUM_VALUE']);

    DB::table('shared_budget_members')->where('user_id', $this->partner)->update(['read_only' => false]);
    app(SharedBudgetService::class)->archive($this->owner, $this->budget, 1, SharedBudgetResource::toArray(...));

    expect(refusedAt(fn () => recordTransaction($this->owner, $this->ownerWallet->id, sharedExpense($this->budget))))->toContain(['/shared_budget_id', 'INVALID_ENUM_VALUE']);
});

test('leaving keeps amount, date and category, erases the note and stops following', function () {
    $view = recordTransaction($this->partner, $this->partnerWallet->id, sharedExpense($this->budget, ['amount_minor' => 800, 'description' => 'Secret gift']));
    $id = $view->transaction->id;

    app(SharedBudgetService::class)->leave($this->partner, $this->budget);

    $entry = $this->repository->entryFor($this->budget, $id);
    expect($entry->description)->toBeNull()
        ->and($entry->isDetached())->toBeTrue()
        ->and($entry->amountMinor)->toBe(800);

    app(TransactionService::class)->patch($this->partner, $this->partnerWallet->id, $id, ['amount_minor' => 1, 'description' => 'Changed'], 1, presentTransaction());

    $after = $this->repository->entryFor($this->budget, $id);
    expect($after->amountMinor)->toBe(800)->and($after->description)->toBeNull();
});

test('an unchanged link is never refused, even after its author has left', function () {
    $shared = ownedWallet($this->owner);
    addMember($shared->id, $this->partner, 'editor');
    $view = recordTransaction($this->partner, $shared->id, sharedExpense($this->budget));
    app(SharedBudgetService::class)->leave($this->partner, $this->budget);

    $edited = app(TransactionService::class)->patch($this->owner, $shared->id, $view->transaction->id, ['amount_minor' => 77], 1, presentTransaction());

    expect($edited->transaction->sharedBudgetId)->toBe($this->budget);
});

test('every followed entry equals its transaction', function () {
    $a = recordTransaction($this->owner, $this->ownerWallet->id, sharedExpense($this->budget, ['amount_minor' => 11, 'date' => '2026-10-05']));
    $b = recordTransaction($this->partner, $this->partnerWallet->id, sharedExpense($this->budget, ['amount_minor' => 22, 'date' => '2026-10-06']));
    app(TransactionService::class)->patch($this->owner, $this->ownerWallet->id, $a->transaction->id, ['amount_minor' => 33, 'date' => '2026-10-07'], 1, presentTransaction());

    $mismatches = DB::select('select e.id from shared_budget_entries e join transactions t on t.id = e.transaction_id where e.detached_at is null and e.deleted_at is null and (e.amount_minor <> t.amount_minor or e.date <> t.date or e.category_id <> t.category_id)');

    expect($mismatches)->toBe([])->and($b->transaction->amountMinor)->toBe(22);
});

test('deleting the wallet keeps the entries and they stop following', function () {
    $view = recordTransaction($this->owner, $this->ownerWallet->id, sharedExpense($this->budget, ['amount_minor' => 640]));

    app(WalletService::class)->delete($this->owner, $this->ownerWallet->id, 1, presentWallet());

    $entry = $this->repository->entryFor($this->budget, $view->transaction->id);
    expect($entry->isTombstoned())->toBeFalse()
        ->and($entry->isDetached())->toBeTrue()
        ->and($entry->amountMinor)->toBe(640);
});

<?php

use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
use App\Modules\Budgeting\Domain\SharedBudgets\Ports\SharedBudgetRepository;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Domain\Recurring\Refusals\RecurringStateInvalid;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Facades\DB;

/*
 * A recurring rule names a Shared Budget like a transaction does (ADR-0027
 * rule 4): its confirmed occurrences count toward it, and a budget the author
 * can no longer use is dropped, never a failed confirmation.
 */

beforeEach(function () {
    freezeClock('2026-10-05 10:00:00');
    $this->world = recurringWorld();
    $this->owner = $this->world['owner'];
    $this->wallet = $this->world['wallet'];
    $this->budget = createSharedBudget($this->owner->id);
    $this->repository = app(SharedBudgetRepository::class);
});

it('stores the link on the rule and an automatic confirmation writes the entry', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'shared_budget_id' => $this->budget]));
    expect($rule->rule->sharedBudgetId)->toBe($this->budget);

    freezeClock('2026-11-01 08:00:00');
    runRecurringJob();

    $transaction = Transaction::query()->sole();
    $entry = $this->repository->entryFor($this->budget, $transaction->id);
    expect($transaction->shared_budget_id)->toBe($this->budget)
        ->and($entry->amountMinor)->toBe(450000)
        ->and($entry->createdBy)->toBe($this->owner->id);
});

it('refuses a budget the author may not use', function () {
    $stranger = User::factory()->create()->id;
    $foreign = createSharedBudget($stranger);

    expect(fn () => createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['shared_budget_id' => $foreign])))->toThrow(RecurringStateInvalid::class);
});

it('keeps the link when a rule write does not mention it', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['shared_budget_id' => $this->budget]));
    $body = recurringRuleBody(['amount_minor' => 500000], 1);
    unset($body['shared_budget_id']);

    $saved = saveRecurringRule($this->owner->id, $this->wallet->id, $rule->rule->id, $body);

    expect($saved->rule->sharedBudgetId)->toBe($this->budget);
});

it('drops the budget, and still confirms, when the author has left it', function () {
    createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['auto_confirm' => true, 'shared_budget_id' => $this->budget]));
    $other = User::factory()->create()->id;
    joinSharedBudget($this->budget, $other);
    app(SharedBudgetService::class)->transferOwnership($this->owner->id, $this->budget, $other, 1, SharedBudgetResource::toArray(...));
    app(SharedBudgetService::class)->leave($this->owner->id, $this->budget);

    freezeClock('2026-11-01 08:00:00');
    $totals = runRecurringJob();

    $transaction = Transaction::query()->sole();
    expect($totals['confirmed'])->toBe(1)
        ->and($transaction->shared_budget_id)->toBeNull()
        ->and(DB::table('shared_budget_entries')->count())->toBe(0);
});

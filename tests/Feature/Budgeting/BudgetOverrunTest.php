<?php

use App\Modules\Budgeting\Application\BudgetService;
use App\Modules\Budgeting\Application\Listeners\EvaluateBudgetsAfterPosting;
use App\Modules\Budgeting\Domain\Events\BudgetExceeded;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Events\TransactionPosted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Budget Overrun (spec 023, FR-026, US4, ADR-0025): the first time a budget's
 * spending goes strictly above its limit in the current Budget Period, one
 * record and one `BudgetExceeded`; never again for that period.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    Event::fake([BudgetExceeded::class]);
    $this->owner = person('owner@example.test');
    $this->wallet = ownedWallet($this->owner->id);
    $this->budget = createBudget($this->owner->id, ['amount_minor' => 100000]);
});

function overrunsOf(string $budgetId): array
{
    return DB::table('budget_overruns')->where('budget_id', $budgetId)->get()->map(fn (object $row): array => ['period_start' => $row->period_start, 'threshold' => $row->threshold, 'user_id' => $row->user_id])->all();
}

function exceededCount(): int
{
    return count(Event::dispatched(BudgetExceeded::class));
}

test('crossing strictly above the limit records one overrun and publishes one event', function () {
    spend($this->owner->id, $this->wallet->id, 60000);
    expect(exceededCount())->toBe(0);

    spend($this->owner->id, $this->wallet->id, 40001);

    expect(overrunsOf($this->budget->id))->toBe([['period_start' => '2026-10-01', 'threshold' => 100, 'user_id' => $this->owner->id]]);
    Event::assertDispatched(BudgetExceeded::class, fn (BudgetExceeded $event): bool => $event->budgetId === $this->budget->id
        && $event->userId === $this->owner->id
        && $event->periodStart === '2026-10-01'
        && $event->threshold === 100
        && ! empty($event->eventId)
        && ! property_exists($event, 'amountMinor'));
    expect(exceededCount())->toBe(1);
});

test('spending equal to the limit is not over it', function () {
    spend($this->owner->id, $this->wallet->id, 100000);

    expect(overrunsOf($this->budget->id))->toBe([])->and(exceededCount())->toBe(0);
});

test('further spending, voiding and re-recording record nothing more', function () {
    $first = spend($this->owner->id, $this->wallet->id, 100001);
    expect(exceededCount())->toBe(1);

    spend($this->owner->id, $this->wallet->id, 5000);
    $service = app(TransactionService::class);
    $service->delete($this->owner->id, $this->wallet->id, $first->transaction->id, $first->transaction->version, presentTransaction());
    spend($this->owner->id, $this->wallet->id, 200000);
    $deleted = $service->restore($this->owner->id, $this->wallet->id, $first->transaction->id, $first->transaction->version + 1, presentTransaction());

    expect(overrunsOf($this->budget->id))->toHaveCount(1)->and(exceededCount())->toBe(1)
        ->and($deleted->transaction->id)->toBe($first->transaction->id);
});

test('a new period starts fresh: a new overrun and one new event', function () {
    spend($this->owner->id, $this->wallet->id, 100001);

    freezeClock('2026-11-10 10:00:00');
    spend($this->owner->id, $this->wallet->id, 100002, ['date' => '2026-11-05']);

    expect(array_column(overrunsOf($this->budget->id), 'period_start'))->toEqualCanonicalizing(['2026-10-01', '2026-11-01'])
        ->and(exceededCount())->toBe(2);
});

test('lowering the limit below what is spent records one overrun, and raising it records none', function () {
    spend($this->owner->id, $this->wallet->id, 70000);
    $service = app(BudgetService::class);

    $service->patch($this->owner->id, $this->budget->id, 50000, 1, presentBudget());
    expect(overrunsOf($this->budget->id))->toHaveCount(1)->and(exceededCount())->toBe(1);

    $service->patch($this->owner->id, $this->budget->id, 20000, 2, presentBudget());
    $service->patch($this->owner->id, $this->budget->id, 900000, 3, presentBudget());
    expect(overrunsOf($this->budget->id))->toHaveCount(1)->and(exceededCount())->toBe(1);
});

test('a whole save that lowers the limit is evaluated too, an identical one is not', function () {
    spend($this->owner->id, $this->wallet->id, 70000);

    saveBudget($this->owner->id, $this->budget->id, budgetBody(['amount_minor' => 100000], 1));
    expect(exceededCount())->toBe(0);

    saveBudget($this->owner->id, $this->budget->id, budgetBody(['amount_minor' => 60000], 1));
    expect(exceededCount())->toBe(1);
});

test('an expense dated in a past period never records an overrun for it', function () {
    spend($this->owner->id, $this->wallet->id, 500000, ['date' => '2026-09-20']);

    expect(overrunsOf($this->budget->id))->toBe([])->and(exceededCount())->toBe(0);
});

test('an expense dated after the current period records nothing yet', function () {
    spend($this->owner->id, $this->wallet->id, 500000, ['date' => '2026-11-03']);

    expect(overrunsOf($this->budget->id))->toBe([]);
});

test('an archived budget never alerts', function () {
    app(BudgetService::class)->archive($this->owner->id, $this->budget->id, 1, presentBudget());

    spend($this->owner->id, $this->wallet->id, 500000);

    expect(overrunsOf($this->budget->id))->toBe([])->and(exceededCount())->toBe(0);
});

test('a budget for all expenses counts every expense category', function () {
    $all = createBudget($this->owner->id, ['category_id' => null, 'amount_minor' => 50000]);

    spend($this->owner->id, $this->wallet->id, 30000, ['category_id' => nthPlatformCategoryId(1)]);
    spend($this->owner->id, $this->wallet->id, 30000, ['category_id' => nthPlatformCategoryId(2)]);

    expect(overrunsOf($all->id))->toHaveCount(1)->and(overrunsOf($this->budget->id))->toBe([]);
});

test('a budget for one category ignores other categories', function () {
    spend($this->owner->id, $this->wallet->id, 500000, ['category_id' => nthPlatformCategoryId(1)]);

    expect(overrunsOf($this->budget->id))->toBe([]);
});

test('spending in another currency is ignored', function () {
    $usd = ownedWallet($this->owner->id, ['currency' => 'USD']);

    spend($this->owner->id, $usd->id, 500000, ['currency' => 'USD']);

    expect(overrunsOf($this->budget->id))->toBe([])->and(exceededCount())->toBe(0);
});

test('incomes and transfers never take a budget over', function () {
    $second = ownedWallet($this->owner->id);

    recordTransaction($this->owner->id, $this->wallet->id, incomeBody(['amount_minor' => 500000]));
    recordTransaction($this->owner->id, $this->wallet->id, transferBody($second->id, ['amount_minor' => 500000]));

    expect(overrunsOf($this->budget->id))->toBe([]);
});

test('a partner amending the author transaction is evaluated for the author, not the partner', function () {
    $partner = person('partner@example.test');
    addMember($this->wallet->id, $partner->id, 'editor');
    $partnerBudget = createBudget($partner->id, ['amount_minor' => 100000]);
    $spent = spend($this->owner->id, $this->wallet->id, 60000);
    expect(exceededCount())->toBe(0);

    saveTransaction($partner->id, $this->wallet->id, $spent->transaction->id, transactionBody(['amount_minor' => 150000], $spent->transaction->version));

    expect(overrunsOf($this->budget->id))->toHaveCount(1)
        ->and(overrunsOf($partnerBudget->id))->toBe([])
        ->and(exceededCount())->toBe(1);
});

test('another person spending in a shared wallet does not count for my budget', function () {
    $partner = person('partner@example.test');
    addMember($this->wallet->id, $partner->id, 'editor');

    spend($partner->id, $this->wallet->id, 500000);

    expect(overrunsOf($this->budget->id))->toBe([]);
});

test('the listener redelivered records and publishes nothing more', function () {
    $spent = spend($this->owner->id, $this->wallet->id, 150000);
    $event = new TransactionPosted((string) Str::uuid7(), now()->toIso8601String(), $spent->transaction->id, $this->wallet->id, null, 'expense', '2026-10-01', null, platformCategoryId(), null, $this->owner->id, 'recorded');

    app(EvaluateBudgetsAfterPosting::class)->handle($event);
    app(EvaluateBudgetsAfterPosting::class)->handle($event);

    expect(overrunsOf($this->budget->id))->toHaveCount(1)->and(exceededCount())->toBe(1);
});

test('the listener ignores a transaction that is gone', function () {
    $event = new TransactionPosted((string) Str::uuid7(), now()->toIso8601String(), (string) Str::uuid7(), $this->wallet->id, null, 'expense', '2026-10-01', null, null, null, $this->owner->id, 'recorded');

    app(EvaluateBudgetsAfterPosting::class)->handle($event);

    expect(exceededCount())->toBe(0);
});

test('deleting the budget row takes its overruns with it', function () {
    spend($this->owner->id, $this->wallet->id, 150000);
    expect(overrunsOf($this->budget->id))->toHaveCount(1);

    DB::table('budgets')->where('id', $this->budget->id)->delete();

    expect(DB::table('budget_overruns')->count())->toBe(0);
});

test('the database allows only the 100 percent threshold and one row per budget, period and threshold', function () {
    $row = ['id' => (string) Str::uuid7(), 'budget_id' => $this->budget->id, 'user_id' => $this->owner->id, 'period_start' => '2026-10-01', 'crossed_at' => now()];

    expect(fn () => DB::transaction(fn () => DB::table('budget_overruns')->insert([...$row, 'threshold' => 80])))->toThrow(QueryException::class);

    DB::table('budget_overruns')->insert([...$row, 'threshold' => 100]);
    expect(fn () => DB::transaction(fn () => DB::table('budget_overruns')->insert([...$row, 'id' => (string) Str::uuid7(), 'threshold' => 100])))->toThrow(UniqueConstraintViolationException::class);
});

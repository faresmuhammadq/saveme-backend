<?php

use App\Modules\Budgeting\Application\BudgetQueryService;
use App\Modules\Budgeting\Application\BudgetService;
use App\Modules\Wallets\Application\Recurring\Dto\RuleView;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * What counts as spending (spec 015, FR-010 to FR-013, US2 S1-S4, S6): the
 * owner's own live expenses, in the budget's currency, category and Budget
 * Period, in wallets they belong to; totals from postings, computed on read.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    $this->owner = person('owner@example.test');
    $this->wallet = ownedWallet($this->owner->id);
    $this->groceries = platformCategoryId();
    $this->dining = nthPlatformCategoryId(1);
    $this->budget = createBudget($this->owner->id, ['category_id' => $this->groceries]);
});

function spentOf(object $test, ?string $date = null): int
{
    return budgetProgress($test->owner->id, $test->budget->id, $date)->spent->amountMinor;
}

test('the owner\'s expenses in the category, currency and period are summed', function () {
    spend($this->owner->id, $this->wallet->id, 100000, ['date' => '2026-10-01']);
    spend($this->owner->id, $this->wallet->id, 250000, ['date' => '2026-10-31']);

    $progress = budgetProgress($this->owner->id, $this->budget->id);

    expect($progress->spent->amountMinor)->toBe(350000)
        ->and($progress->remaining->amountMinor)->toBe(250000)
        ->and($progress->overLimit)->toBeFalse()
        ->and($progress->period->start)->toBe('2026-10-01')
        ->and($progress->period->end)->toBe('2026-10-31')
        ->and($progress->daysElapsed)->toBe(15)
        ->and($progress->projectedEnd?->amountMinor)->toBe(723334);
});

test('transfers, incomes, deleted transactions, other periods and other categories do not count', function () {
    $second = ownedWallet($this->owner->id);
    spend($this->owner->id, $this->wallet->id, 100000);

    recordTransaction($this->owner->id, $this->wallet->id, transferBody($second->id, ['amount_minor' => 5000]));
    recordTransaction($this->owner->id, $this->wallet->id, incomeBody(['amount_minor' => 7000]));
    spend($this->owner->id, $this->wallet->id, 9000, ['category_id' => $this->dining]);
    spend($this->owner->id, $this->wallet->id, 1100, ['date' => '2026-09-30']);
    spend($this->owner->id, $this->wallet->id, 1200, ['date' => '2026-11-01']);
    $gone = spend($this->owner->id, $this->wallet->id, 1300);
    app(TransactionService::class)->delete($this->owner->id, $this->wallet->id, $gone->transaction->id, 1, presentTransaction());

    expect(spentOf($this))->toBe(100000)
        ->and(spentOf($this, '2026-09-30'))->toBe(1100)
        ->and(spentOf($this, '2026-11-02'))->toBe(1200);
});

test('spending in another currency is named in other_currencies and never summed', function () {
    $usd = ownedWallet($this->owner->id, ['currency' => 'USD']);
    spend($this->owner->id, $this->wallet->id, 100000);
    spend($this->owner->id, $usd->id, 5000, ['currency' => 'USD']);

    $progress = budgetProgress($this->owner->id, $this->budget->id);

    expect($progress->spent->amountMinor)->toBe(100000)
        ->and($progress->otherCurrencies)->toBe(['USD']);

    $other = createBudget($this->owner->id, ['category_id' => $this->dining]);
    expect(budgetProgress($this->owner->id, $other->id)->otherCurrencies)->toBe([]);
});

test('an all-expenses budget sums every expense category, own and platform', function () {
    $own = ownedCategory($this->owner->id);
    spend($this->owner->id, $this->wallet->id, 100000);
    spend($this->owner->id, $this->wallet->id, 20000, ['category_id' => $this->dining]);
    spend($this->owner->id, $this->wallet->id, 3000, ['category_id' => $own->id]);
    recordTransaction($this->owner->id, $this->wallet->id, incomeBody(['amount_minor' => 99999]));
    $all = createBudget($this->owner->id, ['category_id' => null]);

    expect(budgetProgress($this->owner->id, $all->id)->spent->amountMinor)->toBe(123000);
});

test('scheduled and skipped occurrences have no postings and never count', function () {
    $rule = createRecurringRule($this->owner->id, $this->wallet->id, recurringRuleBody(['category_id' => $this->groceries, 'start_date' => '2026-10-20']));
    $occurrences = occurrencesOf($rule->rule->id);
    saveRecurringOccurrence($this->owner->id, $this->wallet->id, $occurrences->first()->id, occurrenceBody($occurrences->first(), ['status' => 'skipped']));

    expect($rule)->toBeInstanceOf(RuleView::class)
        ->and(spentOf($this))->toBe(0)
        ->and(spentOf($this, '2026-11-20'))->toBe(0);
});

test('a backdated edit and a re-categorisation are reflected on both periods at once', function () {
    $tx = spend($this->owner->id, $this->wallet->id, 100000, ['date' => '2026-10-10'])->transaction;

    app(TransactionService::class)->patch($this->owner->id, $this->wallet->id, $tx->id, ['date' => '2026-09-20'], 1, presentTransaction());

    expect(spentOf($this))->toBe(0)->and(spentOf($this, '2026-09-20'))->toBe(100000);

    app(TransactionService::class)->patch($this->owner->id, $this->wallet->id, $tx->id, ['category_id' => $this->dining], 2, presentTransaction());

    expect(spentOf($this, '2026-09-20'))->toBe(0);
});

test('a restored transaction counts again', function () {
    $tx = spend($this->owner->id, $this->wallet->id, 100000)->transaction;
    app(TransactionService::class)->delete($this->owner->id, $this->wallet->id, $tx->id, 1, presentTransaction());

    expect(spentOf($this))->toBe(0);

    app(TransactionService::class)->restore($this->owner->id, $this->wallet->id, $tx->id, 2, presentTransaction());

    expect(spentOf($this))->toBe(100000);
});

test('totals come from postings: a corrupted wallet balance changes nothing', function () {
    spend($this->owner->id, $this->wallet->id, 100000);
    Wallet::query()->whereKey($this->wallet->id)->update(['balance_minor' => 987654321]);

    expect(spentOf($this))->toBe(100000);
});

test('deleting a wallet removes its transactions from spent on the next read', function () {
    $other = ownedWallet($this->owner->id);
    spend($this->owner->id, $this->wallet->id, 100000);
    spend($this->owner->id, $other->id, 40000);

    expect(spentOf($this))->toBe(140000);

    app(WalletService::class)->delete($this->owner->id, $other->id, 1, presentWallet());

    expect(spentOf($this))->toBe(100000);
});

test('an archived wallet still counts', function () {
    spend($this->owner->id, $this->wallet->id, 100000);
    Wallet::query()->whereKey($this->wallet->id)->update(['archived_at' => now()]);

    expect(spentOf($this))->toBe(100000);
});

test('a progress page runs one spending query however many budgets it holds', function () {
    foreach (range(1, 6) as $_) {
        createBudget($this->owner->id, ['category_id' => $this->dining]);
    }
    spend($this->owner->id, $this->wallet->id, 100000);

    DB::enableQueryLog();
    $page = app(BudgetQueryService::class)->progressList($this->owner->id, null, null, 50);
    $spending = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'postings'));
    DB::disableQueryLog();

    expect($page->items)->toHaveCount(7)->and($spending)->toHaveCount(1);
});

test('the progress list leaves out archived budgets; an empty list runs no spending query', function () {
    app(BudgetService::class)->archive($this->owner->id, $this->budget->id, 1, presentBudget());

    DB::enableQueryLog();
    $page = app(BudgetQueryService::class)->progressList($this->owner->id, null, null, 50);
    $spending = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'postings'));
    DB::disableQueryLog();

    expect($page->items)->toBe([])->and($spending)->toHaveCount(0)
        ->and(budgetProgress($this->owner->id, $this->budget->id)->budgetId)->toBe($this->budget->id);
});

test('no log record of a budget write or a progress read carries an amount', function () {
    $records = [];
    Log::listen(function (MessageLogged $logged) use (&$records): void {
        $records[] = $logged->message.' '.json_encode($logged->context);
    });

    $budget = createBudget($this->owner->id, ['category_id' => $this->groceries, 'amount_minor' => 7654321]);
    spend($this->owner->id, $this->wallet->id, 1234567);
    budgetProgress($this->owner->id, $budget->id);
    app(BudgetQueryService::class)->progressList($this->owner->id, null, null, 50);
    app(BudgetService::class)->patch($this->owner->id, $budget->id, 7654322, 1, presentBudget());

    $log = implode("\n", $records);

    expect($log)->not->toContain('7654321')->not->toContain('7654322')->not->toContain('1234567')
        ->and($log)->not->toContain('amount_minor')->not->toContain('"spent"');
});

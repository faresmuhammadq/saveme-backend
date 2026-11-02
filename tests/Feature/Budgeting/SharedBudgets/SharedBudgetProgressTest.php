<?php

use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetReports;
use App\Modules\Budgeting\Application\SharedBudgets\SharedBudgetService;
use App\Modules\Budgeting\Domain\SharedBudgets\Refusals\SharedBudgetNotFound;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetLineResource;
use App\Modules\Budgeting\Http\SharedBudgets\Resources\SharedBudgetResource;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use Illuminate\Support\Str;

/*
 * Shared Budget progress and reports (ADR-0027, ADR-0011 rule 7): exact sums
 * of entries, the limits each period had, and the same figures for everyone.
 */

function groceriesLine(string $owner, string $budget, ?int $limit): string
{
    $line = (string) Str::uuid7();
    app(SharedBudgetService::class)->saveLine($owner, $budget, $line, ['category_id' => platformCategoryId(), 'limit_minor' => $limit], 0, SharedBudgetLineResource::toArray(...));

    return $line;
}

beforeEach(function () {
    $this->travelTo('2026-10-15 12:00:00');
    $this->owner = User::factory()->create()->id;
    $this->partner = User::factory()->create()->id;
    $this->budget = createSharedBudget($this->owner, ['limit_minor' => 10000]);
    joinSharedBudget($this->budget, $this->partner);
    $this->ownerWallet = ownedWallet($this->owner);
    $this->partnerWallet = ownedWallet($this->partner);
    $this->reports = app(SharedBudgetReports::class);
});

function spendOnSharedBudget(string $user, string $walletId, string $budget, int $amount, string $date, ?string $category = null): void
{
    recordTransaction($user, $walletId, transactionBody(['shared_budget_id' => $budget, 'amount_minor' => $amount, 'date' => $date, 'category_id' => $category ?? platformCategoryId()]));
}

test('progress sums both members\' entries, per line, unlisted and per member, with exact limits', function () {
    $line = groceriesLine($this->owner, $this->budget, 6000);
    spendOnSharedBudget($this->owner, $this->ownerWallet->id, $this->budget, 2500, '2026-10-03');
    spendOnSharedBudget($this->partner, $this->partnerWallet->id, $this->budget, 1500, '2026-10-04');
    spendOnSharedBudget($this->partner, $this->partnerWallet->id, $this->budget, 700, '2026-10-05', platformCategoryId('expense') === nthPlatformCategoryId(1) ? nthPlatformCategoryId(2) : nthPlatformCategoryId(1));

    $progress = $this->reports->progress($this->partner, $this->budget, null);

    expect($progress->period->start)->toBe('2026-10-01')
        ->and($progress->overall['spent']->amountMinor)->toBe(4700)
        ->and($progress->overall['limit']->amountMinor)->toBe(10000)
        ->and($progress->overall['remaining']->amountMinor)->toBe(5300)
        ->and($progress->overall['over_limit'])->toBeFalse()
        ->and($progress->lines[0]['line']->id)->toBe($line)
        ->and($progress->lines[0]['figure']['spent']->amountMinor)->toBe(4000)
        ->and($progress->lines[0]['figure']['limit']->amountMinor)->toBe(6000)
        ->and($progress->unlisted->amountMinor)->toBe(700)
        ->and(array_map(fn ($money) => $money->amountMinor, $progress->byMember))->toEqual([$this->owner => 2500, $this->partner => 2200]);
});

test('both members read identical figures and a non-member reads nothing', function () {
    spendOnSharedBudget($this->owner, $this->ownerWallet->id, $this->budget, 900, '2026-10-02');

    $a = $this->reports->progress($this->owner, $this->budget, null);
    $b = $this->reports->progress($this->partner, $this->budget, null);
    $stranger = User::factory()->create()->id;

    expect($a->overall['spent']->amountMinor)->toBe($b->overall['spent']->amountMinor)
        ->and($a->period)->toEqual($b->period)
        ->and(fn () => $this->reports->progress($stranger, $this->budget, null))->toThrow(SharedBudgetNotFound::class);
});

test('a past period reports the limit it had, not today\'s', function () {
    spendOnSharedBudget($this->owner, $this->ownerWallet->id, $this->budget, 8000, '2026-10-02');

    $this->travelTo('2026-11-15 12:00:00');
    app(SharedBudgetService::class)->patch($this->owner, $this->budget, ['limit_minor' => 99999], 1, SharedBudgetResource::toArray(...));

    $october = $this->reports->progress($this->owner, $this->budget, '2026-10-20');
    $november = $this->reports->progress($this->owner, $this->budget, null);

    expect($october->overall['limit']->amountMinor)->toBe(10000)
        ->and($october->overall['over_limit'])->toBeFalse()
        ->and($october->projectedEnd)->toBeNull()
        ->and($november->overall['limit']->amountMinor)->toBe(99999);
});

test('going over is reported exactly and the projection is the largest share', function () {
    spendOnSharedBudget($this->owner, $this->ownerWallet->id, $this->budget, 12000, '2026-10-02');

    $progress = $this->reports->progress($this->owner, $this->budget, null);

    expect($progress->overall['over_limit'])->toBeTrue()
        ->and($progress->overall['remaining']->amountMinor)->toBe(-2000)
        ->and($progress->daysElapsed)->toBe(15)
        ->and($progress->projectedEnd->amountMinor)->toBe((int) ceil(12000 * 31 / 15));
});

test('a voided or unlinked transaction leaves the figures', function () {
    $view = recordTransaction($this->owner, $this->ownerWallet->id, transactionBody(['shared_budget_id' => $this->budget, 'amount_minor' => 700, 'date' => '2026-10-02']));
    app(TransactionService::class)->delete($this->owner, $this->ownerWallet->id, $view->transaction->id, 1, presentTransaction());

    expect($this->reports->progress($this->owner, $this->budget, null)->overall['spent']->amountMinor)->toBe(0);
});

test('a member who left keeps their spending in the figures, with no name to show', function () {
    spendOnSharedBudget($this->partner, $this->partnerWallet->id, $this->budget, 300, '2026-10-02');
    app(SharedBudgetService::class)->leave($this->partner, $this->budget);

    $progress = $this->reports->progress($this->owner, $this->budget, null);

    expect($progress->overall['spent']->amountMinor)->toBe(300)->and(array_keys($progress->byMember))->toBe([$this->partner]);
});

test('the summary covers any range and the trend one row per period with that period\'s limit', function () {
    spendOnSharedBudget($this->owner, $this->ownerWallet->id, $this->budget, 100, '2026-09-10');
    spendOnSharedBudget($this->owner, $this->ownerWallet->id, $this->budget, 200, '2026-10-10');

    $summary = $this->reports->summary($this->partner, $this->budget, '2026-09-01', '2026-10-31');
    $trend = $this->reports->trend($this->partner, $this->budget, '2026-09-01', '2026-10-31');

    expect($summary['spent']->amountMinor)->toBe(300)
        ->and($trend)->toHaveCount(2)
        ->and($trend[0]['period']->start)->toBe('2026-09-01')
        ->and($trend[0]['spent']->amountMinor)->toBe(100)
        ->and($trend[1]['spent']->amountMinor)->toBe(200)
        ->and($this->reports->trend($this->owner, $this->budget, '2020-01-01', '2026-10-31'))->toBeNull();
});

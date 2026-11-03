<?php

use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Reporting\Domain\ReportRange;
use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Application\Categories\PlatformCategoryQueryService;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Domain\Transactions\PostingDerivation;
use App\Modules\Wallets\Infrastructure\Database\Seeders\PlatformCategorySeeder;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Posting;
use App\Modules\Wallets\Infrastructure\Models\Transaction;
use Illuminate\Support\Str;

/*
 * Balance adjustments (ADR-0028): a dated, listed transaction that raises or
 * lowers a wallet's balance, and is never spending or income.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    $this->owner = person('owner@example.test');
    $this->wallet = ownedWallet($this->owner->id, ['opening_balance_minor' => 10000, 'balance_minor' => 10000]);
    $this->id = (string) Str::uuid7();
});

function adjustmentBody(string $kind, int $amount = 2500, array $changes = []): array
{
    return transactionBody(['kind' => $kind, 'category_id' => null, 'amount_minor' => $amount, 'description' => 'Fix', ...$changes]);
}

test('an adjustment in raises the balance, an adjustment out lowers it, and the ledger stays zero-sum', function () {
    saveTransaction($this->owner->id, $this->wallet->id, $this->id, adjustmentBody('adjustment_in', 2500));

    expect(walletBalance($this->wallet->id))->toBe(12500);

    saveTransaction($this->owner->id, $this->wallet->id, (string) Str::uuid7(), adjustmentBody('adjustment_out', 4000));

    expect(walletBalance($this->wallet->id))->toBe(8500)
        ->and(Posting::query()->where('category_id', PostingDerivation::ADJUSTMENT_CATEGORY_ID)->count())->toBe(2)
        ->and(Transaction::query()->whereNotNull('category_id')->count())->toBe(0);
    expectLedgerIntact();
});

test('editing the amount, deleting and restoring an adjustment move the balance with it', function () {
    $view = saveTransaction($this->owner->id, $this->wallet->id, $this->id, adjustmentBody('adjustment_out', 1000));

    saveTransaction($this->owner->id, $this->wallet->id, $this->id, adjustmentBody('adjustment_out', 3000, ['version' => $view->transaction->version]));
    expect(walletBalance($this->wallet->id))->toBe(7000);

    app(TransactionService::class)->delete($this->owner->id, $this->wallet->id, $this->id, 2, presentTransaction());
    expect(walletBalance($this->wallet->id))->toBe(10000);

    app(TransactionService::class)->restore($this->owner->id, $this->wallet->id, $this->id, 3, presentTransaction());
    expect(walletBalance($this->wallet->id))->toBe(7000);
    expectLedgerIntact();
});

test('an adjustment refuses a category, a counter wallet and a Shared Budget', function () {
    $other = ownedWallet($this->owner->id);

    expect(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->wallet->id, $this->id, adjustmentBody('adjustment_in', 100, ['category_id' => platformCategoryId('income')])))))
        ->toBe(['/category_id INVALID_ENUM_VALUE'])
        ->and(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->wallet->id, $this->id, adjustmentBody('adjustment_in', 100, ['counter_wallet_id' => $other->id])))))
        ->toBe(['/counter_wallet_id INVALID_ENUM_VALUE']);

    $budget = createSharedBudget($this->owner->id);
    $body = adjustmentBody('adjustment_in', 100, ['shared_budget_id' => $budget]);

    expect(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->wallet->id, $this->id, $body))))->toBe(['/shared_budget_id INVALID_ENUM_VALUE'])
        ->and(Transaction::query()->count())->toBe(0);
});

test('the direction of an adjustment never changes', function () {
    $view = saveTransaction($this->owner->id, $this->wallet->id, $this->id, adjustmentBody('adjustment_in'));

    expect(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->wallet->id, $this->id, adjustmentBody('adjustment_out', 2500, ['version' => $view->transaction->version])))))
        ->toBe(['/kind IMMUTABLE_FIELD']);
});

test('a viewer may not adjust a wallet', function () {
    $viewer = person('viewer@example.test');
    addMember($this->wallet->id, $viewer->id, 'viewer');

    expect(refusalOf(fn () => saveTransaction($viewer->id, $this->wallet->id, $this->id, adjustmentBody('adjustment_in')))?->errorCode)->toBe(ErrorCode::Forbidden);
});

test('the internal category is never listed, seeded over, or accepted as an expense category', function () {
    (new PlatformCategorySeeder)->run();

    $listed = array_map(fn ($category): string => $category->id, app(PlatformCategoryQueryService::class)->all());

    expect($listed)->not->toContain(PostingDerivation::ADJUSTMENT_CATEGORY_ID);
    expect(fieldErrorsOf(refusalOf(fn () => saveTransaction($this->owner->id, $this->wallet->id, $this->id, transactionBody(['category_id' => PostingDerivation::ADJUSTMENT_CATEGORY_ID])))))
        ->toBe(['/category_id INVALID_ENUM_VALUE']);
    expect(Category::query()->whereKey(PostingDerivation::ADJUSTMENT_CATEGORY_ID)->value('archived_at'))->toBeNull();
});

test('an adjustment is neither income nor expense in a report, and the balance still reconciles', function () {
    spend($this->owner->id, $this->wallet->id, 3000, ['date' => '2026-10-05']);
    saveTransaction($this->owner->id, $this->wallet->id, $this->id, adjustmentBody('adjustment_in', 2500, ['date' => '2026-10-06']));
    saveTransaction($this->owner->id, $this->wallet->id, (string) Str::uuid7(), adjustmentBody('adjustment_out', 700, ['date' => '2026-10-07']));

    $figures = app(ReportQueryService::class)->walletSummary($this->owner->id, $this->wallet->id, new ReportRequest(range: ReportRange::explicit('2026-10-01', '2026-10-31')))->figures;

    expect($figures->income->amountMinor)->toBe(0)
        ->and($figures->expenses->amountMinor)->toBe(3000)
        ->and($figures->transfersIn->amountMinor)->toBe(0)
        ->and($figures->transfersOut->amountMinor)->toBe(0)
        ->and($figures->adjustmentsIn->amountMinor)->toBe(2500)
        ->and($figures->adjustmentsOut->amountMinor)->toBe(700)
        ->and($figures->balanceStart->amountMinor)->toBe(10000)
        ->and($figures->balanceEnd->amountMinor)->toBe(10000 - 3000 + 2500 - 700)
        ->and($figures->balanceEnd->amountMinor)->toBe(walletBalance($this->wallet->id));
});

test('an adjustment never counts against a budget', function () {
    $category = platformCategoryId();
    $budget = createBudget($this->owner->id, ['category_id' => null]);
    saveTransaction($this->owner->id, $this->wallet->id, $this->id, adjustmentBody('adjustment_out', 9000));

    expect(budgetProgress($this->owner->id, $budget->id)->spent->amountMinor)->toBe(0);

    spend($this->owner->id, $this->wallet->id, 1200, ['category_id' => $category]);

    expect(budgetProgress($this->owner->id, $budget->id)->spent->amountMinor)->toBe(1200);
});

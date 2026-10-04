<?php

use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Reporting\Domain\CategoryItem;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Infrastructure\Models\Category;

/*
 * The by-category report (spec 019, US2 S1-S5, FR-011, FR-012).
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    $this->owner = person('owner@example.test');
    $this->wallet = ownedWallet($this->owner->id);
    $this->groceries = platformCategoryId();
    $this->transport = nthPlatformCategoryId(1);
    $this->gym = ownedCategory($this->owner->id, ['name' => 'Gym']);
});

function byCategory(object $test, ?string $userId = null, array $request = [])
{
    return app(ReportQueryService::class)->walletByCategory($userId ?? $test->owner->id, $test->wallet->id, new ReportRequest(...$request))->breakdown;
}

function itemSummary(array $items): array
{
    return array_map(fn (CategoryItem $item): array => [$item->label, $item->value->amountMinor], $items);
}

test('the independent-test numbers: totals, labels and order', function () {
    spend($this->owner->id, $this->wallet->id, 300000, ['category_id' => $this->groceries]);
    spend($this->owner->id, $this->wallet->id, 50000, ['category_id' => $this->groceries]);
    spend($this->owner->id, $this->wallet->id, 120000, ['category_id' => $this->transport]);
    spend($this->owner->id, $this->wallet->id, 80000, ['category_id' => $this->gym->id]);

    $expenses = byCategory($this)->expenses;
    $groceriesKey = Category::query()->whereKey($this->groceries)->value('system_key');
    $transportKey = Category::query()->whereKey($this->transport)->value('system_key');

    expect($expenses->total->amountMinor)->toBe(550000)
        ->and(itemSummary($expenses->items))->toBe([[$groceriesKey, 350000], [$transportKey, 120000], [$this->gym->id, 80000]])
        ->and($expenses->items[2]->systemKey)->toBeNull()
        ->and($expenses->items[0]->categoryId)->toBe($this->groceries);
});

test('transfers are in neither list and incomes are under income', function () {
    $second = ownedWallet($this->owner->id);
    spend($this->owner->id, $this->wallet->id, 1000, ['category_id' => $this->groceries]);
    recordTransaction($this->owner->id, $this->wallet->id, transferBody($second->id, ['amount_minor' => 5000]));
    recordTransaction($this->owner->id, $this->wallet->id, incomeBody(['amount_minor' => 900000]));

    $breakdown = byCategory($this);

    expect($breakdown->expenses->total->amountMinor)->toBe(1000)
        ->and($breakdown->income->total->amountMinor)->toBe(900000)
        ->and($breakdown->income->items)->toHaveCount(1)
        ->and($breakdown->income->items[0]->value->amountMinor)->toBe(900000);
});

test('a partner\'s personal category in a shared wallet is labelled by its id', function () {
    $partner = person('partner@example.test');
    addMember($this->wallet->id, $partner->id, 'editor');
    $theirs = ownedCategory($partner->id, ['name' => 'Gym']);
    spend($partner->id, $this->wallet->id, 4000, ['category_id' => $theirs->id]);
    spend($this->owner->id, $this->wallet->id, 6000, ['category_id' => $this->gym->id]);

    $forOwner = byCategory($this);
    $forPartner = byCategory($this, $partner->id);

    expect(itemSummary($forOwner->expenses->items))->toBe([[$this->gym->id, 6000], [$theirs->id, 4000]])
        ->and(itemSummary($forPartner->expenses->items))->toBe(itemSummary($forOwner->expenses->items));
});

test('an archived category and a retired platform category are reported', function () {
    spend($this->owner->id, $this->wallet->id, 700, ['category_id' => $this->gym->id]);
    spend($this->owner->id, $this->wallet->id, 900, ['category_id' => $this->transport]);
    Category::query()->whereKey($this->gym->id)->update(['archived_at' => now()]);
    Category::query()->whereKey($this->transport)->update(['archived_at' => now()]);

    expect(byCategory($this)->expenses->total->amountMinor)->toBe(1600)
        ->and(byCategory($this)->expenses->items)->toHaveCount(2);
});

test('a re-categorised transaction moves between items on the next read', function () {
    $transaction = spend($this->owner->id, $this->wallet->id, 1500, ['category_id' => $this->groceries])->transaction;

    expect(byCategory($this)->expenses->items[0]->categoryId)->toBe($this->groceries);

    app(TransactionService::class)->patch($this->owner->id, $this->wallet->id, $transaction->id, ['category_id' => $this->transport], 1, presentTransaction());

    $items = byCategory($this)->expenses->items;

    expect($items)->toHaveCount(1)->and($items[0]->categoryId)->toBe($this->transport);
});

test('only the range counts, and a deleted transaction counts nowhere', function () {
    spend($this->owner->id, $this->wallet->id, 100, ['date' => '2026-09-30']);
    $gone = spend($this->owner->id, $this->wallet->id, 200, ['date' => '2026-10-10'])->transaction;
    spend($this->owner->id, $this->wallet->id, 400, ['date' => '2026-10-11']);
    app(TransactionService::class)->delete($this->owner->id, $this->wallet->id, $gone->id, 1, presentTransaction());

    expect(byCategory($this)->expenses->total->amountMinor)->toBe(400)
        ->and(byCategory($this, request: ['date' => '2026-09-15'])->expenses->total->amountMinor)->toBe(100);
});

<?php

use App\Modules\Identity\Infrastructure\Models\Profile;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Reporting\Application\Dto\ExcludedWallet;
use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;

/*
 * Reports across the caller's wallets (spec 019, US4 S1-S6 and S8, FR-006 to
 * FR-008): one currency, current memberships, internal transfers cancelled.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    $this->owner = person('owner@example.test');
    $this->cash = ownedWallet($this->owner->id, ['opening_balance_minor' => 100000]);
    $this->bank = ownedWallet($this->owner->id, ['opening_balance_minor' => 50000]);
    $this->card = ownedWallet($this->owner->id, ['type' => 'credit_card', 'opening_balance_minor' => -20000]);
    $this->usd = ownedWallet($this->owner->id, ['currency' => 'USD', 'opening_balance_minor' => 7000]);
    $this->service = app(ReportQueryService::class);

    recordTransaction($this->owner->id, $this->cash->id, incomeBody(['amount_minor' => 1000000, 'date' => '2026-10-02']));
    spend($this->owner->id, $this->cash->id, 300000, ['date' => '2026-10-03']);
    spend($this->owner->id, $this->bank->id, 40000, ['date' => '2026-10-04']);
    spend($this->owner->id, $this->card->id, 90000, ['date' => '2026-10-05']);
    spend($this->owner->id, $this->usd->id, 1234, ['date' => '2026-10-06', 'currency' => 'USD']);
    recordTransaction($this->owner->id, $this->cash->id, transferBody($this->bank->id, ['amount_minor' => 100000, 'date' => '2026-10-07']));
});

function summaryAcross(object $test, array $request = [], ?string $userId = null)
{
    return $test->service->summary($userId ?? $test->owner->id, new ReportRequest(...$request));
}

function excludedIds(array $excluded): array
{
    return array_map(fn (ExcludedWallet $wallet): string => $wallet->walletId.':'.$wallet->currency, $excluded);
}

test('totals equal the sum of the wallet reports and the internal transfer appears nowhere', function () {
    $across = summaryAcross($this)->figures;
    $sums = ['income' => 0, 'expenses' => 0, 'balance_start' => 0, 'balance_end' => 0];

    foreach ([$this->cash, $this->bank, $this->card] as $wallet) {
        $figures = $this->service->walletSummary($this->owner->id, $wallet->id, new ReportRequest)->figures;
        $sums['income'] += $figures->income->amountMinor;
        $sums['expenses'] += $figures->expenses->amountMinor;
        $sums['balance_start'] += $figures->balanceStart->amountMinor;
        $sums['balance_end'] += $figures->balanceEnd->amountMinor;
    }

    expect($across->income->amountMinor)->toBe($sums['income'])
        ->and($across->expenses->amountMinor)->toBe($sums['expenses'])
        ->and($across->balanceStart->amountMinor)->toBe($sums['balance_start'])
        ->and($across->balanceEnd->amountMinor)->toBe($sums['balance_end'])
        ->and($across->income->amountMinor)->toBe(1000000)
        ->and($across->expenses->amountMinor)->toBe(430000)
        ->and($across->transfersIn->amountMinor)->toBe(0)
        ->and($across->transfersOut->amountMinor)->toBe(0)
        ->and($across->balanceStart->amountMinor)->toBe(130000)
        ->and($across->balanceEnd->amountMinor)->toBe(700000);
});

test('a transfer into a wallet the person is not a member of counts as transfers_out', function () {
    $partner = person('partner@example.test');
    $theirs = ownedWallet($partner->id);
    $membership = addMember($theirs->id, $this->owner->id, 'editor');
    recordTransaction($this->owner->id, $this->bank->id, transferBody($theirs->id, ['amount_minor' => 50000, 'date' => '2026-10-08']));
    WalletMember::query()->whereKey($membership->id)->update(['deleted_at' => now()]);

    $across = summaryAcross($this)->figures;

    expect($across->transfersOut->amountMinor)->toBe(50000)
        ->and($across->transfersIn->amountMinor)->toBe(0)
        ->and($across->balanceEnd->amountMinor)->toBe(650000);
});

test('excluded wallets are the other currencies, sorted by id, archived included', function () {
    $archivedUsd = ownedWallet($this->owner->id, ['currency' => 'USD', 'archived_at' => now()]);
    $eur = ownedWallet($this->owner->id, ['currency' => 'EUR']);

    $report = summaryAcross($this);
    $expected = collect([$this->usd->id.':USD', $archivedUsd->id.':USD', $eur->id.':EUR'])->sort()->values()->all();

    expect(excludedIds($report->excluded ?? []))->toBe($expected)
        ->and($report->currency)->toBe('EGP');
});

test('the currency parameter flips coverage', function () {
    $report = summaryAcross($this, ['currency' => 'USD']);

    expect($report->currency)->toBe('USD')
        ->and($report->figures->expenses->amountMinor)->toBe(1234)
        ->and($report->figures->balanceStart->amountMinor)->toBe(7000)
        ->and($report->figures->balanceEnd->amountMinor)->toBe(5766)
        ->and(excludedIds($report->excluded ?? []))->toBe(collect([$this->cash->id, $this->bank->id, $this->card->id])->sort()->map(fn ($id) => $id.':EGP')->values()->all());
});

test('the default currency is the profile\'s, and EGP without a profile', function () {
    Profile::query()->where('user_id', $this->owner->id)->update(['default_currency' => 'USD']);

    expect(summaryAcross($this)->currency)->toBe('USD');

    $bare = User::factory()->create();
    ownedWallet($bare->id, ['currency' => 'EGP']);

    expect(summaryAcross($this, userId: $bare->id)->currency)->toBe('EGP');
});

test('a wallet the person left drops out, and a wallet joined today counts for last month', function () {
    $partner = person('partner@example.test');
    $shared = ownedWallet($partner->id, ['opening_balance_minor' => 1000]);
    spend($partner->id, $shared->id, 700, ['date' => '2026-09-12']);
    $membership = addMember($shared->id, $this->owner->id, 'viewer');

    $lastMonth = fn () => summaryAcross($this, ['date' => '2026-09-15'])->figures;

    expect($lastMonth()->expenses->amountMinor)->toBe(700)
        ->and($lastMonth()->balanceEnd->amountMinor)->toBe(1000 - 700 + 100000 + 50000 - 20000);

    WalletMember::query()->whereKey($membership->id)->update(['deleted_at' => now()]);

    expect($lastMonth()->expenses->amountMinor)->toBe(0);
});

test('a person with no wallet in the currency gets zeros and every wallet excluded', function () {
    $report = summaryAcross($this, ['currency' => 'GBP']);

    expect($report->figures->income->amountMinor)->toBe(0)
        ->and($report->figures->balanceStart->amountMinor)->toBe(0)
        ->and($report->figures->balanceEnd->currency->code)->toBe('GBP')
        ->and($report->excluded)->toHaveCount(4)
        ->and($this->service->byCategory($this->owner->id, new ReportRequest(currency: 'GBP'))->breakdown->expenses->items)->toBe([])
        ->and($this->service->trend($this->owner->id, new ReportRequest(currency: 'GBP'))->series->buckets)->toHaveCount(6);
});

test('by-category sums a platform category across wallets under one system key', function () {
    $groceries = platformCategoryId();
    spend($this->owner->id, $this->cash->id, 1000, ['category_id' => $groceries]);
    spend($this->owner->id, $this->bank->id, 2000, ['category_id' => $groceries]);

    $items = $this->service->byCategory($this->owner->id, new ReportRequest)->breakdown->expenses->items;
    $key = Category::query()->whereKey($groceries)->value('system_key');
    $grocery = collect($items)->firstWhere('label', $key);

    expect($grocery->value->amountMinor)->toBe(433000)
        ->and(collect($items)->where('label', $key))->toHaveCount(1);
});

test('the trend sums per bucket across wallets', function () {
    spend($this->owner->id, $this->bank->id, 500, ['date' => '2026-09-02']);
    spend($this->owner->id, $this->card->id, 250, ['date' => '2026-09-20']);

    $series = $this->service->trend($this->owner->id, new ReportRequest(months: 2))->series;

    expect(array_map(fn ($money): int => $money->amountMinor, $series->expenses))->toBe([750, 430000])
        ->and(array_map(fn ($money): int => $money->amountMinor, $series->income))->toBe([0, 1000000]);
});

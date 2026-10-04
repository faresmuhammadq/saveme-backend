<?php

use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Wallets\Infrastructure\Models\Category;
use App\Modules\Wallets\Infrastructure\Models\Occurrence;
use Illuminate\Support\Str;

/*
 * Planned figures (spec 019, US5 S1-S5, FR-014; research R-6): scheduled
 * occurrences, kept apart from the actual figures and the balances.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    $this->owner = person('owner@example.test');
    $this->cash = ownedWallet($this->owner->id, ['opening_balance_minor' => 100000]);
    $this->bank = ownedWallet($this->owner->id);
    $this->service = app(ReportQueryService::class);
    $this->planned = new ReportRequest(includePlanned: true);
});

/**
 * A rent rule on the wallet whose first occurrence is moved to the date.
 */
function rentOn(object $test, string $walletId, string $date, array $changes = []): Occurrence
{
    $rule = createRecurringRule($test->owner->id, $walletId, recurringRuleBody(['start_date' => '2026-10-20', ...$changes]));
    $occurrence = occurrencesOf($rule->rule->id)->first();
    Occurrence::query()->whereKey($occurrence->id)->update(['date' => $date]);

    return $occurrence;
}

function plannedOf(object $test, string $walletId, ?ReportRequest $request = null)
{
    return $test->service->walletSummary($test->owner->id, $walletId, $request ?? $test->planned);
}

test('a scheduled rent later in the range is planned, and the confirmed salary stays actual', function () {
    recordTransaction($this->owner->id, $this->cash->id, incomeBody(['amount_minor' => 1000000, 'date' => '2026-10-01']));
    rentOn($this, $this->cash->id, '2026-10-28', ['amount_minor' => 500000]);

    $report = plannedOf($this, $this->cash->id);

    expect($report->planned?->expenses->amountMinor)->toBe(500000)
        ->and($report->planned?->income->amountMinor)->toBe(0)
        ->and($report->figures->income->amountMinor)->toBe(1000000)
        ->and($report->figures->expenses->amountMinor)->toBe(0)
        ->and($report->figures->balanceEnd->amountMinor)->toBe(1100000);
});

test('confirmed and skipped occurrences are absent, and a due or missed one is included', function () {
    $due = rentOn($this, $this->cash->id, '2026-10-05', ['amount_minor' => 111]);
    $confirmed = rentOn($this, $this->cash->id, '2026-10-20', ['amount_minor' => 222, 'description' => 'Two']);
    $skipped = rentOn($this, $this->cash->id, '2026-10-21', ['amount_minor' => 333, 'description' => 'Three']);
    Occurrence::query()->whereKey($confirmed->id)->update(['status' => 'confirmed', 'transaction_id' => (string) Str::uuid7()]);
    Occurrence::query()->whereKey($skipped->id)->update(['status' => 'skipped']);

    expect($due->status)->toBe('scheduled')
        ->and(plannedOf($this, $this->cash->id)->planned?->expenses->amountMinor)->toBe(111);
});

test('a gam\'eya payout is planned income on its date and the contribution planned expense', function () {
    createRecurringRule($this->owner->id, $this->cash->id, recurringRuleBody([
        'preset' => 'gameya', 'amount_minor' => 100000, 'description' => 'Gam\'eya', 'anchor_day' => 5, 'start_date' => '2026-10-05',
        'occurrence_count' => 10, 'payout_round' => 1, 'payout_category_id' => platformCategoryId('income'),
    ]));

    $report = plannedOf($this, $this->cash->id);

    expect($report->planned?->income->amountMinor)->toBe(1000000)
        ->and($report->planned?->expenses->amountMinor)->toBe(100000);
});

test('an occurrence in another currency is absent', function () {
    $usd = ownedWallet($this->owner->id, ['currency' => 'USD']);
    rentOn($this, $usd->id, '2026-10-25', ['currency' => 'USD', 'amount_minor' => 9999]);
    rentOn($this, $this->cash->id, '2026-10-26', ['amount_minor' => 700]);

    expect($this->service->summary($this->owner->id, $this->planned)->planned?->expenses->amountMinor)->toBe(700)
        ->and($this->service->summary($this->owner->id, new ReportRequest(currency: 'USD', includePlanned: true))->planned?->expenses->amountMinor)->toBe(9999);
});

test('a transfer rule between covered wallets is in each wallet report but absent across wallets', function () {
    rentOn($this, $this->cash->id, '2026-10-25', ['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => $this->bank->id, 'amount_minor' => 4000]);

    $across = $this->service->summary($this->owner->id, $this->planned)->planned;

    expect(plannedOf($this, $this->cash->id)->planned?->transfersOut->amountMinor)->toBe(4000)
        ->and(plannedOf($this, $this->bank->id)->planned?->transfersIn->amountMinor)->toBe(4000)
        ->and($across?->transfersIn->amountMinor)->toBe(0)
        ->and($across?->transfersOut->amountMinor)->toBe(0)
        ->and($across?->expenses->amountMinor)->toBe(0);
});

test('by-category items gain planned, a planned-only item is listed with value zero, and the actual items are unchanged', function () {
    $groceries = platformCategoryId();
    $transport = nthPlatformCategoryId(1);
    spend($this->owner->id, $this->cash->id, 3000, ['category_id' => $groceries]);
    rentOn($this, $this->cash->id, '2026-10-25', ['category_id' => $transport, 'amount_minor' => 4500]);
    $rentKey = Category::query()->whereKey($transport)->value('system_key');

    $with = $this->service->walletByCategory($this->owner->id, $this->cash->id, $this->planned)->breakdown->expenses;
    $without = $this->service->walletByCategory($this->owner->id, $this->cash->id, new ReportRequest)->breakdown->expenses;
    $plannedOnly = collect($with->items)->firstWhere('label', $rentKey);

    expect($plannedOnly->value->amountMinor)->toBe(0)
        ->and($plannedOnly->planned?->amountMinor)->toBe(4500)
        ->and($with->items[0]->planned?->amountMinor)->toBe(0)
        ->and($with->planned?->amountMinor)->toBe(4500)
        ->and($with->total->amountMinor)->toBe(3000)
        ->and($without->items)->toHaveCount(1)
        ->and($without->items[0]->planned)->toBeNull()
        ->and($without->planned)->toBeNull();
});

test('the trend carries planned series by period', function () {
    rentOn($this, $this->cash->id, '2026-10-25', ['amount_minor' => 4500]);

    $with = $this->service->walletTrend($this->owner->id, $this->cash->id, new ReportRequest(months: 2, includePlanned: true))->series;
    $without = $this->service->walletTrend($this->owner->id, $this->cash->id, new ReportRequest(months: 2))->series;
    $minor = fn (?array $series): ?array => $series === null ? null : array_map(fn ($money): int => $money->amountMinor, $series);

    expect($minor($with->plannedExpenses))->toBe([0, 4500])
        ->and($minor($with->plannedIncome))->toBe([0, 0])
        ->and($minor($with->expenses))->toBe([0, 0])
        ->and($without->plannedExpenses)->toBeNull();
});

test('actual figures and balances are identical with and without the flag, and planned is null without it', function () {
    spend($this->owner->id, $this->cash->id, 3000, ['date' => '2026-10-03']);
    rentOn($this, $this->cash->id, '2026-10-25', ['amount_minor' => 4500]);

    $with = plannedOf($this, $this->cash->id);
    $without = plannedOf($this, $this->cash->id, new ReportRequest);

    expect($with->figures)->toEqual($without->figures)
        ->and($without->planned)->toBeNull()
        ->and($with->planned)->not->toBeNull();
});

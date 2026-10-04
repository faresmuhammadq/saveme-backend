<?php

use App\Modules\Reporting\Application\Dto\ReportRequest;
use App\Modules\Reporting\Application\ReportQueryService;
use App\Modules\Reporting\Domain\ReportRange;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;

/*
 * The wallet summary (spec 019, US1 S2-S5, FR-009, FR-010, FR-021; SC-001):
 * the independent-test numbers, balances from the ledger, never the cache.
 */

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    $this->owner = person('owner@example.test');
    ['cash' => $this->cash, 'bank' => $this->bank] = reportScenario($this->owner->id);
});

function walletSummaryOf(object $test, string $walletId, array $query = []): array
{
    $report = app(ReportQueryService::class)->walletSummary($test->owner->id, $walletId, new ReportRequest(...$query));

    return [
        'income' => $report->figures->income->amountMinor,
        'expenses' => $report->figures->expenses->amountMinor,
        'net' => $report->figures->net->amountMinor,
        'transfers_in' => $report->figures->transfersIn->amountMinor,
        'transfers_out' => $report->figures->transfersOut->amountMinor,
        'balance_start' => $report->figures->balanceStart->amountMinor,
        'balance_end' => $report->figures->balanceEnd->amountMinor,
    ];
}

test('the independent-test numbers', function () {
    expect(walletSummaryOf($this, $this->cash->id))->toBe([
        'income' => 1000000, 'expenses' => 350000, 'net' => 650000,
        'transfers_in' => 0, 'transfers_out' => 200000,
        'balance_start' => 80000, 'balance_end' => 530000,
    ])->and(walletSummaryOf($this, $this->bank->id))->toMatchArray(['transfers_in' => 200000, 'transfers_out' => 0, 'balance_start' => 0, 'balance_end' => 200000]);
});

test('a deleted transaction counts nowhere', function () {
    $gone = spend($this->owner->id, $this->cash->id, 7777, ['date' => '2026-10-06']);
    app(TransactionService::class)->delete($this->owner->id, $this->cash->id, $gone->transaction->id, 1, presentTransaction());

    expect(walletSummaryOf($this, $this->cash->id))->toMatchArray(['expenses' => 350000, 'balance_end' => 530000]);
});

test('a transaction on the day before from is only in balance_start, and the edges are inside', function () {
    spend($this->owner->id, $this->cash->id, 100, ['date' => '2026-09-30']);
    spend($this->owner->id, $this->cash->id, 10, ['date' => '2026-10-01']);
    spend($this->owner->id, $this->cash->id, 1, ['date' => '2026-10-31']);
    spend($this->owner->id, $this->cash->id, 1000, ['date' => '2026-11-01']);

    expect(walletSummaryOf($this, $this->cash->id))->toMatchArray([
        'expenses' => 350011,
        'balance_start' => 80000 - 100,
        'balance_end' => 530000 - 100 - 11,
    ]);
});

test('a corrupted balance cache changes nothing, and an open-ended to equals opening plus postings', function () {
    Wallet::query()->whereKey($this->cash->id)->update(['balance_minor' => 123456789]);

    $summary = walletSummaryOf($this, $this->cash->id, ['range' => ReportRange::explicit('2026-01-01', '2026-12-31')]);

    expect($summary['balance_end'])->toBe(530000)
        ->and($summary['balance_start'])->toBe(100000);
});

test('a credit-card wallet reports negative balances', function () {
    $card = ownedWallet($this->owner->id, ['type' => 'credit_card', 'opening_balance_minor' => -300000]);
    spend($this->owner->id, $card->id, 420000, ['date' => '2026-10-07']);
    recordTransaction($this->owner->id, $this->bank->id, transferBody($card->id, ['amount_minor' => 300000, 'date' => '2026-10-08']));

    expect(walletSummaryOf($this, $card->id))->toMatchArray([
        'expenses' => 420000, 'net' => -420000, 'transfers_in' => 300000,
        'balance_start' => -300000, 'balance_end' => -420000,
    ]);
});

test('a transfer from a since-deleted wallet into this live wallet counts as transfers_in', function () {
    $other = person('other@example.test');
    $source = ownedWallet($other->id, ['opening_balance_minor' => 50000]);
    addMember($source->id, $this->owner->id, 'editor');
    recordTransaction($this->owner->id, $source->id, transferBody($this->cash->id, ['amount_minor' => 9000, 'date' => '2026-10-09']));
    app(WalletService::class)->delete($other->id, $source->id, 1, presentWallet());

    expect(walletSummaryOf($this, $this->cash->id))->toMatchArray(['transfers_in' => 9000, 'balance_end' => 539000]);
});

test('no report log line carries an amount', function () {
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$lines): void {
        $lines[] = $event->message.' '.json_encode($event->context);
    });

    walletSummaryOf($this, $this->cash->id);

    expect(implode("\n", $lines))->not->toContain('350000')->not->toContain('1000000')->not->toContain('530000');
});

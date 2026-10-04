<?php

use App\Modules\Reporting\Domain\LegTotal;
use App\Modules\Reporting\Domain\SummaryFigures;
use App\Modules\Shared\Domain\Money\Money;

/*
 * The summary arithmetic (spec 019, FR-009, FR-010; invariant row 1): the
 * balance identity holds for any set of legs, and internal transfers cancel.
 */

function egp(int $minor): Money
{
    return Money::of($minor, 'EGP');
}

function leg(string $wallet, string $kind, int $minor, ?string $other = null): LegTotal
{
    return new LegTotal($wallet, $kind, $other, egp($minor));
}

test('income and expenses come from the signs of the legs', function () {
    $figures = SummaryFigures::from([leg('a', 'income', 1000000), leg('a', 'expense', -350000)], egp(80000), ['a']);

    expect($figures->income->amountMinor)->toBe(1000000)
        ->and($figures->expenses->amountMinor)->toBe(350000)
        ->and($figures->net->amountMinor)->toBe(650000)
        ->and($figures->balanceEnd->amountMinor)->toBe(730000);
});

test('transfers are in or out per wallet and never income or expense', function () {
    $figures = SummaryFigures::from([
        leg('a', 'transfer', -200000, 'b'),
        leg('a', 'transfer', 50000, 'c'),
    ], egp(500000), ['a']);

    expect($figures->transfersOut->amountMinor)->toBe(200000)
        ->and($figures->transfersIn->amountMinor)->toBe(50000)
        ->and($figures->income->amountMinor)->toBe(0)
        ->and($figures->expenses->amountMinor)->toBe(0)
        ->and($figures->balanceEnd->amountMinor)->toBe(350000);
});

test('liability balances stay negative and unclamped', function () {
    $figures = SummaryFigures::from([leg('card', 'expense', -420000), leg('card', 'transfer', 300000, 'bank')], egp(-300000), ['card']);

    expect($figures->balanceStart->amountMinor)->toBe(-300000)
        ->and($figures->balanceEnd->amountMinor)->toBe(-420000)
        ->and($figures->net->amountMinor)->toBe(-420000);
});

test('with a covered set a transfer between covered wallets appears nowhere but the balances still net', function () {
    $legs = [
        leg('cash', 'transfer', -200000, 'bank'),
        leg('bank', 'transfer', 200000, 'cash'),
        leg('bank', 'transfer', -1000, 'outside'),
        leg('cash', 'income', 5000),
    ];

    $figures = SummaryFigures::from($legs, egp(0), ['cash', 'bank']);

    expect($figures->transfersIn->amountMinor)->toBe(0)
        ->and($figures->transfersOut->amountMinor)->toBe(1000)
        ->and($figures->balanceEnd->amountMinor)->toBe(4000);
});

test('the balance identity holds for random ledgers, whichever wallets are covered', function () {
    mt_srand(19);
    $everyWallet = ['a', 'b', 'c', 'x'];

    for ($run = 0; $run < 50; $run++) {
        $covered = array_slice($everyWallet, 0, mt_rand(1, 3));
        $legs = [];

        for ($i = 0; $i < mt_rand(0, 12); $i++) {
            $amount = mt_rand(1, 100000);
            $source = $everyWallet[mt_rand(0, 3)];

            if (mt_rand(0, 2) < 2) {
                if (in_array($source, $covered, true)) {
                    $kind = mt_rand(0, 1) === 1 ? 'income' : 'expense';
                    $legs[] = leg($source, $kind, $kind === 'income' ? $amount : -$amount);
                }

                continue;
            }

            $destination = $everyWallet[(array_search($source, $everyWallet, true) + mt_rand(1, 3)) % 4];

            if (in_array($source, $covered, true)) {
                $legs[] = leg($source, 'transfer', -$amount, $destination);
            }

            if (in_array($destination, $covered, true)) {
                $legs[] = leg($destination, 'transfer', $amount, $source);
            }
        }

        $start = mt_rand(-100000, 100000);
        $figures = SummaryFigures::from($legs, egp($start), $covered);

        expect($figures->income->amountMinor)->toBeGreaterThanOrEqual(0)
            ->and($figures->expenses->amountMinor)->toBeGreaterThanOrEqual(0)
            ->and($figures->transfersIn->amountMinor)->toBeGreaterThanOrEqual(0)
            ->and($figures->transfersOut->amountMinor)->toBeGreaterThanOrEqual(0)
            ->and($figures->balanceEnd->amountMinor)->toBe($start + array_sum(array_map(fn (LegTotal $leg): int => $leg->amount->amountMinor, $legs)))
            ->and($start + $figures->net->amountMinor + $figures->transfersIn->amountMinor - $figures->transfersOut->amountMinor)->toBe($figures->balanceEnd->amountMinor);
    }
});

test('no legs give zeros in the currency', function () {
    $figures = SummaryFigures::empty('USD');

    expect($figures->income->amountMinor)->toBe(0)
        ->and($figures->balanceEnd->currency->code)->toBe('USD')
        ->and($figures->net->isZero())->toBeTrue();
});

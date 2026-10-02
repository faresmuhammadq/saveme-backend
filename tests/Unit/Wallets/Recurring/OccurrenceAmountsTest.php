<?php

use App\Modules\Wallets\Domain\Recurring\AmountBasis;
use App\Modules\Wallets\Domain\Recurring\OccurrenceAmounts;

it('gives every round the rule\'s amount on a per-occurrence basis', function (): void {
    expect(OccurrenceAmounts::forRound(AmountBasis::PerOccurrence, 450000, 'EGP', null, 7))->toBe(450000)
        ->and(OccurrenceAmounts::forRound(AmountBasis::PerOccurrence, 450000, 'EGP', 12, 1))->toBe(450000);
});

it('splits a total over the rounds with the remainder to the earliest', function (): void {
    $shares = array_map(fn (int $round): int => OccurrenceAmounts::forRound(AmountBasis::Total, 1000000, 'EGP', 12, $round), range(1, 12));

    expect(array_slice($shares, 0, 4))->toBe([83334, 83334, 83334, 83334])
        ->and(array_slice($shares, 4))->toBe(array_fill(0, 8, 83333))
        ->and(array_sum($shares))->toBe(1000000);
});

it('pays out the sum of every round\'s scheduled amount', function (): void {
    expect(OccurrenceAmounts::payout(AmountBasis::PerOccurrence, 100000, 10))->toBe(1000000)
        ->and(OccurrenceAmounts::payout(AmountBasis::Total, 1000000, 10))->toBe(1000000);
});

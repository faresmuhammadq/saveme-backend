<?php

use App\Modules\Shared\Domain\Money\Money;

/*
 * P-03 rule 4 (spec 014, FR-012): a total split over equal parts sums
 * exactly to the total, the remainder going one minor unit to each of the
 * first parts.
 */

it('splits 1,000,000 over 12 into four of 83,334 and eight of 83,333', function (): void {
    $shares = array_map(fn (Money $share): int => $share->amountMinor, Money::of(1_000_000, 'EGP')->allocate(12));

    expect($shares)->toBe([...array_fill(0, 4, 83_334), ...array_fill(0, 8, 83_333)])
        ->and(array_sum($shares))->toBe(1_000_000);
});

it('splits an exact multiple into equal parts', function (): void {
    $shares = Money::of(5, 'EGP')->allocate(5);

    expect(array_map(fn (Money $share): int => $share->amountMinor, $shares))->toBe([1, 1, 1, 1, 1]);
});

it('leaves one part holding the whole amount', function (): void {
    $shares = Money::of(7, 'USD')->allocate(1);

    expect($shares)->toHaveCount(1)
        ->and($shares[0]->amountMinor)->toBe(7)
        ->and($shares[0]->currency->code)->toBe('USD');
});

it('splits a negative amount symmetrically', function (): void {
    $shares = array_map(fn (Money $share): int => $share->amountMinor, Money::of(-10, 'EGP')->allocate(3));

    expect($shares)->toBe([-4, -3, -3]);
});

it('always sums to the original and differs by at most one minor unit', function (): void {
    foreach (range(1, 200) as $round) {
        $amount = random_int(-9_007_199_254_740_991, 9_007_199_254_740_991);
        $parts = random_int(1, 120);
        $shares = array_map(fn (Money $share): int => $share->amountMinor, Money::of($amount, 'EGP')->allocate($parts));

        expect($shares)->toHaveCount($parts)
            ->and(array_sum($shares))->toBe($amount)
            ->and(max($shares) - min($shares))->toBeLessThanOrEqual(1);
    }
});

it('refuses fewer than one part', function (int $parts): void {
    Money::of(100, 'EGP')->allocate($parts);
})->with([0, -1])->throws(InvalidArgumentException::class);

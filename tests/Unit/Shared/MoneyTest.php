<?php

use App\Modules\Shared\Domain\Money\CurrencyMismatch;
use App\Modules\Shared\Domain\Money\Money;

it('adds and subtracts amounts of one currency', function (): void {
    $sum = Money::of(1_000, 'EGP')->plus(Money::of(250, 'EGP'));

    expect($sum->amountMinor)->toBe(1_250)
        ->and($sum->currency->code)->toBe('EGP')
        ->and($sum->minus(Money::of(1_250, 'EGP'))->isZero())->toBeTrue();
});

it('negates and compares by amount and currency', function (): void {
    expect(Money::of(500, 'USD')->negate()->amountMinor)->toBe(-500)
        ->and(Money::of(500, 'USD')->equals(Money::of(500, 'USD')))->toBeTrue()
        ->and(Money::of(500, 'USD')->equals(Money::of(500, 'EGP')))->toBeFalse()
        ->and(Money::of(500, 'USD')->plus(Money::of(500, 'USD')->negate())->isZero())->toBeTrue();
});

it('refuses arithmetic between currencies', function (): void {
    Money::of(1, 'EGP')->plus(Money::of(1, 'USD'));
})->throws(CurrencyMismatch::class);

it('refuses subtracting a different currency', function (): void {
    Money::of(1, 'EGP')->minus(Money::of(1, 'USD'));
})->throws(CurrencyMismatch::class);

it('holds amounts up to the 2^53 - 1 bound in both directions', function (): void {
    expect(Money::of(Money::MAX_MINOR, 'EGP')->amountMinor)->toBe(9007199254740991)
        ->and(Money::of(-Money::MAX_MINOR, 'EGP')->amountMinor)->toBe(-9007199254740991);
});

it('refuses an amount beyond the bound', function (): void {
    Money::of(Money::MAX_MINOR, 'EGP')->plus(Money::of(1, 'EGP'));
})->throws(OverflowException::class);

it('refuses an unknown currency', function (): void {
    Money::of(1, 'egp');
})->throws(InvalidArgumentException::class);

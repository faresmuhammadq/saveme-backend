<?php

use App\Modules\Shared\Domain\Money\Currency;

/*
 * ISO 4217 codes with their minor units, uppercase only (ADR-0003; spec 008, R-2).
 */

test('active codes are known with their minor units', function () {
    expect(Currency::of('EGP')->minorUnits)->toBe(2)
        ->and(Currency::of('KWD')->minorUnits)->toBe(3)
        ->and(Currency::of('JPY')->minorUnits)->toBe(0);
});

test('lower case, test codes and invented codes are not', function (string $code) {
    expect(Currency::isKnown($code))->toBeFalse()
        ->and(fn () => Currency::of($code))->toThrow(InvalidArgumentException::class);
})->with(['egp', 'XXX', 'XTS', 'ABC', 'XAU']);

<?php

use App\Modules\Billing\Domain\Claims\ClaimReference;

/*
 * The InstaPay reference as claimed (spec 020, FR-006).
 */

test('spaces and hyphens are removed and letters upper-cased', function () {
    expect(ClaimReference::normalise('4815 1623 4211'))->toBe('481516234211')
        ->and(ClaimReference::normalise('ipn-2026-10-05-7731'))->toBe('IPN20261005773'.'1')
        ->and(ClaimReference::normalise('  ab12 cd34  '))->toBe('AB12CD34');
});

test('8 to 32 characters of A-Z and 0-9 are valid after normalising', function (string $raw, bool $valid) {
    expect(ClaimReference::isValid($raw))->toBe($valid);
})->with([
    '8 characters' => [str_repeat('A', 8), true],
    '32 characters' => [str_repeat('9', 32), true],
    '7 characters' => [str_repeat('A', 7), false],
    '33 characters' => [str_repeat('A', 33), false],
    'spaces and hyphens do not count' => ['1234-567 8', true],
    'too short once normalised' => ['12 34 56 7', false],
    'a symbol' => ['ABCD1234!', false],
    'an underscore' => ['ABCD_1234', false],
    'a letter outside A-Z' => ['ABCD123é', false],
    'empty' => ['', false],
]);

<?php

/*
| One-time codes per RFC 6238 over RFC 4226 (spec 018, R-1, R-3;
| contracts/second-factor.md). The no-early-exit rule is a review item.
*/

use App\Modules\Admin\Domain\SecondFactor\Totp;

const RFC_SECRET = '12345678901234567890';

it('computes the RFC 6238 SHA-1 vectors', function (int $unix, string $code) {
    expect(Totp::codeAt(RFC_SECRET, Totp::stepAt($unix)))->toBe($code);
})->with([
    [59, '287082'],
    [1111111109, '081804'],
    [1111111111, '050471'],
    [1234567890, '005924'],
    [2000000000, '279037'],
    [20000000000, '353130'],
]);

it('computes the RFC 4226 HOTP values for counters 0 to 9', function () {
    $expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];

    foreach ($expected as $counter => $code) {
        expect(Totp::codeAt(RFC_SECRET, $counter))->toBe($code);
    }
});

it('maps Unix time to 30-second steps', function () {
    expect(Totp::stepAt(0))->toBe(0)
        ->and(Totp::stepAt(29))->toBe(0)
        ->and(Totp::stepAt(30))->toBe(1)
        ->and(Totp::stepAt(59))->toBe(1);
});

it('accepts the previous, current and next step and returns the matched step', function () {
    $now = 1111111111;
    $step = Totp::stepAt($now);

    foreach ([-1, 0, 1] as $offset) {
        expect(Totp::verify(RFC_SECRET, Totp::codeAt(RFC_SECRET, $step + $offset), $now, null))->toBe($step + $offset);
    }
});

it('refuses a code two steps away', function () {
    $now = 1111111111;
    $step = Totp::stepAt($now);

    foreach ([-2, 2] as $offset) {
        expect(Totp::verify(RFC_SECRET, Totp::codeAt(RFC_SECRET, $step + $offset), $now, null))->toBeNull();
    }
});

it('refuses a step at or before the last accepted one', function () {
    $now = 1111111111;
    $step = Totp::stepAt($now);

    expect(Totp::verify(RFC_SECRET, Totp::codeAt(RFC_SECRET, $step), $now, $step))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, Totp::codeAt(RFC_SECRET, $step - 1), $now, $step))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, Totp::codeAt(RFC_SECRET, $step + 1), $now, $step))->toBe($step + 1);
});

it('tolerates spaces and refuses anything that is not six digits', function (string $input) {
    expect(Totp::verify(RFC_SECRET, $input, 59, null))->toBeNull();
})->with(['five digits' => '28708', 'seven digits' => '2870820', 'letters' => 'abcdef', 'empty' => '', 'mixed' => '28708a']);

it('strips spaces before comparing', function () {
    expect(Totp::verify(RFC_SECRET, '287 082', 59, null))->toBe(1);
});

<?php

/*
| RFC 4648 base32 as authenticator apps expect it (spec 018, R-2).
*/

use App\Modules\Admin\Domain\SecondFactor\Base32;

it('encodes the RFC 4648 section 10 vectors without padding', function (string $plain, string $encoded) {
    expect(Base32::encode($plain))->toBe($encoded);
})->with([
    'empty' => ['', ''],
    'f' => ['f', 'MY'],
    'fo' => ['fo', 'MZXQ'],
    'foo' => ['foo', 'MZXW6'],
    'foob' => ['foob', 'MZXW6YQ'],
    'fooba' => ['fooba', 'MZXW6YTB'],
    'foobar' => ['foobar', 'MZXW6YTBOI'],
]);

it('decodes case-insensitively, ignoring spaces and padding', function () {
    expect(Base32::decode('mzxw 6ytb oi'))->toBe('foobar')
        ->and(Base32::decode('MZXW6YQ='))->toBe('foob')
        ->and(Base32::decode('MY======'))->toBe('f');
});

it('refuses characters outside the alphabet', function (string $input) {
    expect(Base32::decode($input))->toBeNull();
})->with(['zero' => 'MY0', 'one' => 'M1', 'eight' => 'M8', 'nine' => 'M9', 'symbol' => 'M-Y']);

it('round-trips random 20-byte secrets as 32 characters', function () {
    foreach (range(1, 100) as $ignored) {
        $secret = random_bytes(20);
        $encoded = Base32::encode($secret);

        expect($encoded)->toHaveLength(32)
            ->and($encoded)->toMatch('/^[A-Z2-7]{32}$/')
            ->and(Base32::decode($encoded))->toBe($secret);
    }
});

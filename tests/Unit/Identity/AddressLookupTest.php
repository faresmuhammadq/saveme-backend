<?php

use App\Modules\Identity\Domain\Address\AddressNormaliser;
use App\Modules\Identity\Infrastructure\Crypto\HmacAddressLookup;

/*
 * One normalised address, one lookup value (security baseline, Finding a
 * person by address). No framework: the lookup is built with an explicit key.
 */

$lookup = fn (string $key = 'k'): HmacAddressLookup => new HmacAddressLookup(str_repeat($key, 32));

test('case and surrounding whitespace do not make two accounts', function () use ($lookup) {
    expect($lookup()->for(' Ali@Example.com '))->toBe($lookup()->for('ali@example.com'));
});

test('dots and plus-tags are kept, so two people stay two people', function () use ($lookup) {
    expect($lookup()->for('a.b@example.com'))->not->toBe($lookup()->for('ab@example.com'))
        ->and($lookup()->for('a+x@example.com'))->not->toBe($lookup()->for('a@example.com'));
});

test('Unicode compatibility forms normalise (NFKC)', function () {
    expect(AddressNormaliser::normalise('ａ@example.com'))->toBe('a@example.com');
});

test('the value is 64 lowercase hex characters and depends on the key', function () use ($lookup) {
    expect($lookup()->for('a@example.com'))->toMatch('/^[0-9a-f]{64}$/')
        ->and($lookup('k')->for('a@example.com'))->not->toBe($lookup('z')->for('a@example.com'));
});

<?php

use App\Modules\Identity\Infrastructure\Crypto\Keyring;
use App\Modules\Identity\Infrastructure\Crypto\Sealer;

/*
 * Authenticated encryption with a key reference beside each value (security
 * baseline, What is protected at rest, rule 4; D4). The database side of the
 * envelope is proven in tests/Feature/Identity/AddressAtRestTest.php.
 */

$key = fn (): string => random_bytes(32);

test('seal then open round-trips, and names its key', function () use ($key) {
    $k = $key();
    $sealed = Sealer::seal('ada@example.test', $k, 'k1', 'user-1');

    expect($sealed)->toStartWith('v1.k1.')
        ->and(Sealer::open($sealed, $k, 'user-1'))->toBe('ada@example.test');
});

test('the same plaintext sealed twice differs (fresh nonce)', function () use ($key) {
    $k = $key();

    expect(Sealer::seal('same', $k, 'k1', 'u'))->not->toBe(Sealer::seal('same', $k, 'k1', 'u'));
});

test('a tampered value, another owner, or another key is refused', function () use ($key) {
    $k = $key();
    $sealed = Sealer::seal('secret', $k, 'k1', 'user-1');
    $tampered = substr($sealed, 0, -4).(str_ends_with($sealed, 'AAAA') ? 'BBBB' : 'AAAA');

    expect(fn () => Sealer::open($tampered, $k, 'user-1'))->toThrow(RuntimeException::class)
        ->and(fn () => Sealer::open($sealed, $k, 'user-2'))->toThrow(RuntimeException::class)
        ->and(fn () => Sealer::open($sealed, $key(), 'user-1'))->toThrow(RuntimeException::class);
});

test('a wrapped data key stays readable after the active key changes', function () use ($key) {
    $old = base64_encode($key());
    $new = base64_encode($key());
    $dataKey = $key();

    $wrapped = Sealer::seal($dataKey, (new Keyring(['k1' => $old], 'k1'))->key('k1'), 'k1', 'user-1');
    $rotated = new Keyring(['k1' => $old, 'k2' => $new], 'k2');

    expect($rotated->activeKid())->toBe('k2')
        ->and(Sealer::open($wrapped, $rotated->key(Sealer::parse($wrapped)['ref']), 'user-1'))->toBe($dataKey);
});

test('a keyring without its active key refuses to work', function () {
    expect(fn () => (new Keyring([], 'k1'))->activeKid())->toThrow(RuntimeException::class);
});

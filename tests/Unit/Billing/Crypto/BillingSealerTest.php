<?php

use App\Modules\Billing\Infrastructure\Crypto\BillingKeyring;
use App\Modules\Billing\Infrastructure\Crypto\BillingSealer;

/*
 * Claim values are sealed under Billing's own keyring (spec 020, R-5).
 */

function billingKey(string $seed): string
{
    return base64_encode(str_pad($seed, 32, '.'));
}

function billingSealer(string $active = 'b1'): BillingSealer
{
    return new BillingSealer(new BillingKeyring(['b1' => billingKey('one'), 'b2' => billingKey('two')], $active));
}

test('a value round-trips and names the key that sealed it', function () {
    $sealed = billingSealer()->seal('mona@example.com', 'claim-1', 'address');

    expect($sealed)->toStartWith('v1.b1.')
        ->and($sealed)->not->toContain('mona')
        ->and(billingSealer()->open($sealed, 'claim-1', 'address'))->toBe('mona@example.com');
});

test('the same value seals differently each time', function () {
    expect(billingSealer()->seal('x', 'c', 'address'))->not->toBe(billingSealer()->seal('x', 'c', 'address'));
});

test('a value is bound to its claim and its field', function () {
    $sealed = billingSealer()->seal('mona@example.com', 'claim-1', 'address');

    expect(fn () => billingSealer()->open($sealed, 'claim-2', 'address'))->toThrow(RuntimeException::class)
        ->and(fn () => billingSealer()->open($sealed, 'claim-1', 'receipt'))->toThrow(RuntimeException::class);
});

test('an unknown key id or a tampered value fails', function () {
    $sealed = billingSealer()->seal('x', 'c', 'address');

    expect(fn () => billingSealer()->open(str_replace('.b1.', '.b9.', $sealed), 'c', 'address'))->toThrow(RuntimeException::class)
        ->and(fn () => billingSealer()->open($sealed.'AA', 'c', 'address'))->toThrow(RuntimeException::class)
        ->and(fn () => billingSealer()->open('garbage', 'c', 'address'))->toThrow(RuntimeException::class);
});

test('after rotation an old key still opens and new values use the new key', function () {
    $old = billingSealer('b1')->seal('x', 'c', 'address');
    $new = billingSealer('b2')->seal('x', 'c', 'address');

    expect($new)->toStartWith('v1.b2.')
        ->and(billingSealer('b2')->open($old, 'c', 'address'))->toBe('x');
});

test('without an active key nothing is sealed', function () {
    $sealer = new BillingSealer(new BillingKeyring([], null));

    expect(fn () => $sealer->seal('x', 'c', 'address'))->toThrow(RuntimeException::class);
});

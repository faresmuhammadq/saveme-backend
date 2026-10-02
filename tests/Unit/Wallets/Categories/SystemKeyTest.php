<?php

use App\Modules\Wallets\Domain\Categories\SystemKey;

test('a valid key is accepted', function (string $key) {
    expect((new SystemKey($key))->value)->toBe($key);
})->with(['groceries', 'phone_internet', 'ab', str_repeat('a', 40)]);

test('a malformed key is refused', function (string $key) {
    expect(fn () => new SystemKey($key))->toThrow(InvalidArgumentException::class);
})->with(['Groceries', '1abc', 'a', str_repeat('a', 41), 'has-dash', '', 'under_Score']);

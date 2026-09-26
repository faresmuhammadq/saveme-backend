<?php

use App\Modules\Identity\Domain\Password\PasswordPolicy;

/*
 * FR-006: 8 to 128 characters, no composition rules, not the address.
 */

test('length bounds are 8 and 128', function () {
    expect(PasswordPolicy::violations(str_repeat('a', 7), 'x@example.com'))->toBe(['too_short'])
        ->and(PasswordPolicy::violations(str_repeat('a', 8), 'x@example.com'))->toBe([])
        ->and(PasswordPolicy::violations(str_repeat('a', 128), 'x@example.com'))->toBe([])
        ->and(PasswordPolicy::violations(str_repeat('a', 129), 'x@example.com'))->toBe(['too_long']);
});

test('no composition rules apply', function () {
    expect(PasswordPolicy::violations('correct horse', 'x@example.com'))->toBe([])
        ->and(PasswordPolicy::violations('12345678', 'x@example.com'))->toBe([]);
});

test('the password may not be the address, whatever its case', function () {
    expect(PasswordPolicy::violations('Ada@Example.com', 'ada@example.com'))->toBe(['equals_address']);
});

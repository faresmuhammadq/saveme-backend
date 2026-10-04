<?php

/*
| Operator password rules (spec 018, R-15; FR-062).
*/

use App\Modules\Admin\Domain\Operators\OperatorPasswordPolicy;

it('accepts 12 to 128 characters', function () {
    expect(OperatorPasswordPolicy::violations(str_repeat('a', 12), 'ada@example.test'))->toBe([])
        ->and(OperatorPasswordPolicy::violations(str_repeat('a', 128), 'ada@example.test'))->toBe([]);
});

it('counts characters, not bytes', function () {
    expect(OperatorPasswordPolicy::violations(str_repeat('ب', 12), 'ada@example.test'))->toBe([])
        ->and(OperatorPasswordPolicy::violations(str_repeat('ب', 11), 'ada@example.test'))->toBe(['too_short']);
});

it('names each violation', function () {
    expect(OperatorPasswordPolicy::violations('short', 'ada@example.test'))->toBe(['too_short'])
        ->and(OperatorPasswordPolicy::violations(str_repeat('a', 129), 'ada@example.test'))->toBe(['too_long'])
        ->and(OperatorPasswordPolicy::violations(' Ada.Lovelace@Example.Test ', 'ada.lovelace@example.test'))->toContain('equals_email')
        ->and(OperatorPasswordPolicy::violations(str_repeat('b', 14), 'ada@example.test', matchesCurrent: true))->toBe(['reused']);
});

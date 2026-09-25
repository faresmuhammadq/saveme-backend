<?php

/*
| The reason an operator gives for an audited action (spec 006, FR-017, FR-018).
*/

use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Exceptions\InvalidAuditReason;

it('trims the reason', function () {
    expect((new AuditReason("  Reference verified.\n"))->value())->toBe('Reference verified.');
});

it('rejects a blank reason', function (string $reason) {
    new AuditReason($reason);
})->with(['empty' => '', 'spaces' => '   ', 'whitespace' => "\n\t "])
    ->throws(InvalidAuditReason::class, 'blank');

it('rejects a reason longer than 500 characters', function () {
    new AuditReason(str_repeat('a', 501));
})->throws(InvalidAuditReason::class, 'too_long');

it('counts Arabic by character, accepting exactly 500', function () {
    $reason = str_repeat('ب', 500);

    expect((new AuditReason($reason))->value())->toBe($reason);
});

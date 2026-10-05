<?php

use App\Modules\Identity\Domain\Sessions\EndReason;
use App\Modules\Identity\Domain\Sessions\SessionState;

/*
 * Device Session lifecycle (glossary): active → revoked, active → expired.
 */

test('only an active session can end, and an ended one stays ended', function () {
    expect(SessionState::Active->canBecome(SessionState::Revoked))->toBeTrue()
        ->and(SessionState::Active->canBecome(SessionState::Expired))->toBeTrue()
        ->and(SessionState::Revoked->canBecome(SessionState::Active))->toBeFalse()
        ->and(SessionState::Expired->canBecome(SessionState::Revoked))->toBeFalse()
        ->and(SessionState::Revoked->isTerminal())->toBeTrue()
        ->and(SessionState::Active->isTerminal())->toBeFalse();
});

test('the end reasons are the six of spec 007 and account_deleted of spec 022', function () {
    expect(array_map(fn (EndReason $reason): string => $reason->value, EndReason::cases()))
        ->toBe(['signed_out', 'ended_by_owner', 'replaced', 'password_changed', 'password_reset', 'expired', 'account_deleted']);
});

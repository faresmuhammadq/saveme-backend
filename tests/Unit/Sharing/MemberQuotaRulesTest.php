<?php

use App\Modules\Sharing\Domain\MemberQuota;

/*
 * The member quota arithmetic of spec 016 (R-7): owner + active members +
 * live pending invitations within `sharing.max_members_per_wallet`.
 */

test('inviting needs members + pending + 1 within the quota', function () {
    expect(MemberQuota::canInvite(1, 0, 5))->toBeTrue()
        ->and(MemberQuota::canInvite(4, 0, 5))->toBeTrue()
        ->and(MemberQuota::canInvite(4, 1, 5))->toBeFalse()
        ->and(MemberQuota::canInvite(2, 2, 5))->toBeTrue()
        ->and(MemberQuota::canInvite(2, 3, 5))->toBeFalse();
});

test('accepting counts the other pending invitations and the one being accepted', function () {
    expect(MemberQuota::canAccept(4, 0, 5))->toBeTrue()
        ->and(MemberQuota::canAccept(4, 1, 5))->toBeFalse()
        ->and(MemberQuota::canAccept(1, 3, 5))->toBeTrue()
        ->and(MemberQuota::canAccept(5, 0, 5))->toBeFalse();
});

test('Free, with a quota of 1, can never invite or accept', function () {
    expect(MemberQuota::canInvite(1, 0, 1))->toBeFalse()
        ->and(MemberQuota::canAccept(1, 0, 1))->toBeFalse();
});

test('a null quota is unlimited', function () {
    expect(MemberQuota::canInvite(500, 500, null))->toBeTrue()
        ->and(MemberQuota::canAccept(500, 500, null))->toBeTrue()
        ->and(MemberQuota::canPromote(500, null, true))->toBeTrue();
});

test('raising to editor needs sharing on and the wallet within the quota', function () {
    expect(MemberQuota::canPromote(5, 5, true))->toBeTrue()
        ->and(MemberQuota::canPromote(6, 5, true))->toBeFalse()
        ->and(MemberQuota::canPromote(2, 5, false))->toBeFalse()
        ->and(MemberQuota::canPromote(2, 1, true))->toBeFalse();
});

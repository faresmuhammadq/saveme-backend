<?php

use App\Modules\Wallets\Domain\Membership\DemotionPlan;
use App\Modules\Wallets\Domain\Membership\MemberRecord;
use App\Modules\Wallets\Domain\Membership\Role;

/*
 * Which editors a lowered quota turns into viewers (spec 016, R-11, P-09
 * rule 6): owner first, then joining order; only editors beyond the quota;
 * never a removal and never a promotion.
 */

/**
 * @param  list<Role>  $roles  the members in rank order
 * @return list<MemberRecord>
 */
function rankedMembers(array $roles): array
{
    $at = new DateTimeImmutable('2026-10-02T09:00:00Z');

    return array_map(fn (Role $role, int $index): MemberRecord => new MemberRecord(
        id: "member-{$index}",
        walletId: 'wallet-1',
        userId: "user-{$index}",
        role: $role,
        version: 1,
        changeSeq: $index + 1,
        createdAt: $at,
        updatedAt: $at,
        deletedAt: null,
    ), $roles, array_keys($roles));
}

function demotedIds(array $roles, ?int $quota): array
{
    return array_map(fn (MemberRecord $member): string => $member->id, DemotionPlan::editorsBeyond(rankedMembers($roles), $quota));
}

test('Free allows the owner only, so every editor goes', function () {
    expect(demotedIds([Role::Owner, Role::Editor, Role::Editor], 1))->toBe(['member-1', 'member-2']);
});

test('a quota of 3 keeps the owner and the two earliest members and demotes the later editors', function () {
    expect(demotedIds([Role::Owner, Role::Editor, Role::Editor, Role::Editor, Role::Editor], 3))->toBe(['member-3', 'member-4']);
});

test('viewers are never touched, though they occupy a rank', function () {
    expect(demotedIds([Role::Owner, Role::Viewer, Role::Editor, Role::Editor], 3))->toBe(['member-3'])
        ->and(demotedIds([Role::Owner, Role::Viewer, Role::Viewer], 1))->toBe([]);
});

test('no quota means nobody is demoted, and a roomy quota demotes nobody', function () {
    expect(demotedIds([Role::Owner, Role::Editor, Role::Editor], null))->toBe([])
        ->and(demotedIds([Role::Owner, Role::Editor, Role::Editor], 5))->toBe([]);
});

test('a plan already applied demotes nobody further', function () {
    // After the first run the beyond-quota editors are viewers; the same plan finds no editor beyond the quota.
    expect(demotedIds([Role::Owner, Role::Editor, Role::Editor, Role::Viewer, Role::Viewer], 3))->toBe([]);
});

test('the owner is never returned, whatever the quota', function () {
    expect(demotedIds([Role::Owner], 0))->toBe([]);
});

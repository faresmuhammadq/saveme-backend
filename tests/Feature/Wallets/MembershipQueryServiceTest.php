<?php

use App\Modules\Wallets\Application\MembershipQueryService;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;

/*
 * What Identity's deletion plan may ask Wallets about who could inherit a
 * wallet (spec 022, R-9).
 */

test('active members come back in joining order with the owner first and their joined instants, without display names', function () {
    $owner = person();
    $early = person('early@example.test');
    $late = person('late@example.test');
    $left = person('left@example.test');
    $wallet = ownedWallet($owner->id);
    addMember($wallet->id, $late->id, 'viewer');
    addMember($wallet->id, $early->id, 'editor');
    addMember($wallet->id, $left->id, 'editor');
    WalletMember::query()->where('wallet_id', $wallet->id)->where('user_id', $early->id)->update(['created_at' => '2026-01-01 00:00:00+00']);
    WalletMember::query()->where('wallet_id', $wallet->id)->where('user_id', $late->id)->update(['created_at' => '2026-02-01 00:00:00+00']);
    WalletMember::query()->where('wallet_id', $wallet->id)->where('user_id', $left->id)->update(['deleted_at' => now()]);

    $members = app(MembershipQueryService::class)->activeInJoiningOrder($wallet->id);

    expect(array_map(fn ($member): string => $member->userId, $members))->toBe([$owner->id, $early->id, $late->id])
        ->and($members[1]->createdAt->format('Y-m-d'))->toBe('2026-01-01')
        ->and($members[1]->role->value)->toBe('editor');
});

test('the owned live wallets of a person include archived ones and exclude deleted ones', function () {
    $owner = person();
    $live = ownedWallet($owner->id);
    $archived = ownedWallet($owner->id, ['archived_at' => now()]);
    ownedWallet($owner->id, ['deleted_at' => now()]);

    $owned = app(MembershipQueryService::class)->ownedLiveWalletIds($owner->id);

    expect($owned)->toEqualCanonicalizing([$live->id, $archived->id]);
});

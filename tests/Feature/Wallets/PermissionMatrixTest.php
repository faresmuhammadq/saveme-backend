<?php

use App\Modules\Billing\Domain\Refusals\FeatureNotEntitled;
use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Wallets\Application\Authorization\WalletAuthorizer;
use App\Modules\Wallets\Domain\Membership\Permission;
use App\Modules\Wallets\Domain\Membership\PermissionMatrix;
use App\Modules\Wallets\Domain\Membership\Role;
use App\Modules\Wallets\Domain\Refusals\WalletActionForbidden;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Every row of the matrix driven through the authorizer (spec 010, US5; P-08, ADR-0006).
 * The expectations come from PermissionMatrix, which the unit test pins to spec section 6.
 */

beforeEach(function () {
    $this->owner = person('owner@example.test');
    $this->editor = person('editor@example.test');
    $this->viewer = person('viewer@example.test');
    $this->stranger = person('stranger@example.test');
    $this->wallet = ownedWallet($this->owner->id);
    addMember($this->wallet->id, $this->editor->id, 'editor');
    addMember($this->wallet->id, $this->viewer->id, 'viewer');
    // The owner holds Plus so the capability on S-02 never masks the role decision.
    Subscription::factory()->create(['user_id' => $this->owner->id]);
});

test('each role is allowed or forbidden exactly as the matrix says', function (Permission $permission) {
    foreach (['owner' => Role::Owner, 'editor' => Role::Editor, 'viewer' => Role::Viewer] as $who => $role) {
        $call = fn () => app(WalletAuthorizer::class)->authorize($this->{$who}->id, $this->wallet->id, $permission);

        if (PermissionMatrix::allows($role, $permission)) {
            expect($call())->toBe($role);
        } else {
            expect($call)->toThrow(WalletActionForbidden::class);
        }
    }
})->with(Permission::cases());

test('a non-member gets not-found on every row', function (Permission $permission) {
    expect(fn () => app(WalletAuthorizer::class)->authorize($this->stranger->id, $this->wallet->id, $permission))
        ->toThrow(WalletNotFound::class);
})->with(Permission::cases());

test('a wallet that does not exist is not-found, as for a non-member', function () {
    expect(fn () => app(WalletAuthorizer::class)->authorize($this->owner->id, (string) Str::uuid7(), Permission::W01ReadWallet))
        ->toThrow(WalletNotFound::class);
});

test('a member of a deleted wallet is judged by the role they held; others get not-found', function (Permission $permission) {
    $at = now()->format('Y-m-d H:i:s.uP');
    DB::table('wallet_members')->where('wallet_id', $this->wallet->id)->update(['deleted_at' => $at]);
    DB::table('wallets')->where('id', $this->wallet->id)->update(['deleted_at' => $at]);

    foreach (['owner' => Role::Owner, 'editor' => Role::Editor, 'viewer' => Role::Viewer] as $who => $role) {
        if (PermissionMatrix::capabilityFor($permission) !== null) {
            continue;
        }

        $call = fn () => app(WalletAuthorizer::class)->authorize($this->{$who}->id, $this->wallet->id, $permission);

        PermissionMatrix::allows($role, $permission) ? expect($call())->toBe($role) : expect($call)->toThrow(WalletActionForbidden::class);
    }

    expect(fn () => app(WalletAuthorizer::class)->authorize($this->stranger->id, $this->wallet->id, $permission))
        ->toThrow(WalletNotFound::class);
})->with(Permission::cases());

test('S-02 needs sharing on the owner\'s plan, not the caller\'s', function () {
    $free = person('free@example.test');
    $freeWallet = ownedWallet($free->id);
    expect(fn () => app(WalletAuthorizer::class)->authorize($free->id, $freeWallet->id, Permission::S02SendOrRevokeInvitation))
        ->toThrow(FeatureNotEntitled::class);

    expect(app(WalletAuthorizer::class)->authorize($this->owner->id, $this->wallet->id, Permission::S02SendOrRevokeInvitation))->toBe(Role::Owner);

    // A member holding Plus does not lend it to a wallet whose owner has none.
    Subscription::factory()->create(['user_id' => $free->id]);
    $other = person('other@example.test');
    $otherWallet = ownedWallet($other->id);
    addMember($otherWallet->id, $free->id, 'editor');
    expect(fn () => app(WalletAuthorizer::class)->authorize($other->id, $otherWallet->id, Permission::S02SendOrRevokeInvitation))
        ->toThrow(FeatureNotEntitled::class);
});

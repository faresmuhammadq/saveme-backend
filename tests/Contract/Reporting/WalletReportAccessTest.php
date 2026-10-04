<?php

use App\Modules\Billing\Infrastructure\Models\Subscription;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Str;

/*
 * Who may read a wallet report (spec 019, US1 S7, FR-004, FR-020; P-08, matrix
 * row W-01): any active member, anywhere on the three wallet paths; nobody
 * else learns the wallet exists.
 */

const WALLET_REPORT_PATHS = ['summary', 'by-category', 'trend'];

beforeEach(function () {
    freezeClock('2026-10-15 10:00:00');
    [$this->owner, $this->ownerCredential] = walletCaller('owner@example.test');
    $this->wallet = ownedWallet($this->owner->id);
});

test('owner, editor and viewer read all three reports', function () {
    foreach (['editor', 'viewer'] as $role) {
        [$user, $credential] = walletCaller($role.'@example.test');
        addMember($this->wallet->id, $user->id, $role);

        foreach (WALLET_REPORT_PATHS as $path) {
            reportCall($credential, '/wallets/'.$this->wallet->id.'/reports/'.$path)->assertValidRequest()->assertValidResponse(200);
        }
    }

    foreach (WALLET_REPORT_PATHS as $path) {
        reportCall($this->ownerCredential, '/wallets/'.$this->wallet->id.'/reports/'.$path)->assertValidResponse(200);
    }
});

test('a non-member, a former member, a deleted wallet and an unknown id are all 404', function () {
    [, $stranger] = walletCaller('stranger@example.test');
    [$former, $formerCredential] = walletCaller('former@example.test');
    $member = addMember($this->wallet->id, $former->id, 'editor');
    WalletMember::query()->whereKey($member->id)->update(['deleted_at' => now()]);
    $deleted = ownedWallet($this->owner->id);
    Wallet::query()->whereKey($deleted->id)->update(['deleted_at' => now()]);

    foreach (WALLET_REPORT_PATHS as $path) {
        reportCall($stranger, '/wallets/'.$this->wallet->id.'/reports/'.$path)->assertValidResponse(404)->assertJsonPath('code', 'NOT_FOUND');
        reportCall($formerCredential, '/wallets/'.$this->wallet->id.'/reports/'.$path)->assertValidResponse(404);
        reportCall($this->ownerCredential, '/wallets/'.$deleted->id.'/reports/'.$path)->assertValidResponse(404);
        reportCall($this->ownerCredential, '/wallets/'.Str::uuid7().'/reports/'.$path)->assertValidResponse(404);
    }
});

test('a non-member with a malformed query still gets 404, never 400', function () {
    [, $stranger] = walletCaller('stranger@example.test');

    reportCall($stranger, '/wallets/'.$this->wallet->id.'/reports/summary?date=nope&from=x')->assertValidResponse(404);
    reportCall($stranger, '/wallets/'.$this->wallet->id.'/reports/trend?months=99')->assertValidResponse(404);
    reportCall($this->ownerCredential, '/wallets/'.$this->wallet->id.'/reports/trend?months=99')->assertValidResponse(400);
});

test('an unverified member reads: reports are not behind the verification gate', function () {
    [$user, $credential] = walletCaller('unverified@example.test', verified: false);
    addMember($this->wallet->id, $user->id, 'viewer');

    foreach (WALLET_REPORT_PATHS as $path) {
        reportCall($credential, '/wallets/'.$this->wallet->id.'/reports/'.$path)->assertValidResponse(200);
    }
});

test('a wallet whose owner has no subscription is served: nothing is gated by a plan', function () {
    expect(Subscription::query()->where('user_id', $this->owner->id)->count())->toBe(0);

    foreach (WALLET_REPORT_PATHS as $path) {
        reportCall($this->ownerCredential, '/wallets/'.$this->wallet->id.'/reports/'.$path.'?include_planned=true')->assertValidResponse(200);
    }
});

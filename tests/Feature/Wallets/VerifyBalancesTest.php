<?php

use App\Modules\Wallets\Application\Balances\VerifyBalances;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * The drift check (spec 010, FR-007; ADR-0005 rule 6).
 */

test('drift is logged with the wallet id only and repaired', function () {
    $user = person();
    $wallet = ownedWallet($user->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    DB::table('wallets')->where('id', $wallet->id)->update(['balance_minor' => 49999]);
    fakePostings(sum: 700);
    Log::spy();

    expect(app(VerifyBalances::class)->handle())->toBe(1);

    expect(DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'))->toBe(50700);
    Log::shouldHaveReceived('warning')->once()->with('wallet_balance_drift', ['wallet_id' => $wallet->id]);
});

test('no drift means no log and no change', function () {
    $user = person();
    $wallet = ownedWallet($user->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    $versionBefore = DB::table('wallets')->where('id', $wallet->id)->value('version');
    Log::spy();

    expect(app(VerifyBalances::class)->handle())->toBe(0);

    Log::shouldNotHaveReceived('warning');
    expect(DB::table('wallets')->where('id', $wallet->id)->value('version'))->toBe($versionBefore);
});

test('the console command runs the check', function () {
    $this->artisan('wallets:verify-balances')->assertSuccessful();
});

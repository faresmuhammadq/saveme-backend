<?php

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Wallets\Application\Membership\MembershipCounts;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;

/*
 * The counts an operator sees about a person's wallets, never their names
 * (spec 021, FR-021; R-10).
 */

function countsWallet(string $ownerId, array $others = [], array $walletState = []): Wallet
{
    $wallet = Wallet::factory()->create($walletState);
    WalletMember::factory()->owner()->create(['wallet_id' => $wallet->id, 'user_id' => $ownerId]);

    foreach ($others as $userId) {
        WalletMember::factory()->editor()->create(['wallet_id' => $wallet->id, 'user_id' => $userId]);
    }

    return $wallet;
}

beforeEach(function () {
    $this->user = User::factory()->create()->id;
    $this->a = User::factory()->create()->id;
    $this->b = User::factory()->create()->id;
    $this->counts = app(MembershipCounts::class);
});

test('owned, joined and distinct other members are counted', function () {
    countsWallet($this->user, [$this->a, $this->b]);
    countsWallet($this->user, [$this->a]);
    countsWallet($this->a, [$this->user]);
    countsWallet($this->b, [$this->a]);

    $counts = $this->counts->forUser($this->user);

    expect($counts->walletsOwned)->toBe(2)
        ->and($counts->walletsJoined)->toBe(1)
        ->and($counts->otherMembers)->toBe(2);
});

test('deleted wallets and removed members are left out', function () {
    countsWallet($this->user, [$this->a], ['deleted_at' => now()]);
    countsWallet($this->a, [$this->user], ['deleted_at' => now()]);
    $live = countsWallet($this->user);
    WalletMember::factory()->editor()->deleted()->create(['wallet_id' => $live->id, 'user_id' => $this->b]);

    $counts = $this->counts->forUser($this->user);

    expect($counts->walletsOwned)->toBe(1)
        ->and($counts->walletsJoined)->toBe(0)
        ->and($counts->otherMembers)->toBe(0);
});

test('a person with nothing has zero counts', function () {
    $counts = $this->counts->forUser($this->user);

    expect([$counts->walletsOwned, $counts->walletsJoined, $counts->otherMembers])->toBe([0, 0, 0]);
});

test('shared wallets are live wallets with two or more live members', function () {
    countsWallet($this->user, [$this->a]);
    countsWallet($this->a, [$this->b, $this->user]);
    countsWallet($this->b);
    countsWallet($this->user, [$this->a], ['deleted_at' => now()]);
    $removed = countsWallet($this->b);
    WalletMember::factory()->editor()->deleted()->create(['wallet_id' => $removed->id, 'user_id' => $this->a]);

    expect($this->counts->sharedWallets())->toBe(2);
});

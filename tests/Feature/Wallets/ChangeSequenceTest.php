<?php

use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use App\Modules\Wallets\Infrastructure\Models\WalletMember;
use Illuminate\Support\Str;

/*
 * The wallet scope counter (spec 010, FR-012; ADR-0008 rules 2-6, R-3).
 */

/**
 * Every change_seq in a wallet's scope: the wallet's own and each membership's.
 *
 * @return list<int>
 */
function scopeSequences(string $walletId): array
{
    return [
        Wallet::query()->whereKey($walletId)->value('change_seq'),
        ...WalletMember::query()->where('wallet_id', $walletId)->pluck('change_seq')->all(),
    ];
}

function expectScopeIntact(string $walletId): void
{
    $sequences = scopeSequences($walletId);
    $wallet = array_shift($sequences);

    expect(array_unique($sequences))->toHaveCount(count($sequences))
        ->and($wallet)->toBeGreaterThan(max($sequences));
}

test('a create gives the membership its own value and the wallet the last', function () {
    $user = person();

    $wallet = app(WalletService::class)->save($user->id, (string) Str::uuid7(), walletValues(), 0, presentWallet())->view->wallet;

    expect(scopeSequences($wallet->id))->toBe([3, 2])
        ->and($wallet->changeSeq)->toBe(3);
});

test('an edit moves change_seq and version; a replay moves neither', function () {
    $user = person();
    $wallet = app(WalletService::class)->save($user->id, (string) Str::uuid7(), walletValues(), 0, presentWallet())->view->wallet;

    $edited = app(WalletService::class)->patch($user->id, $wallet->id, ['name' => 'New'], 1, presentWallet())->wallet;
    $replayed = app(WalletService::class)->patch($user->id, $wallet->id, ['name' => 'New'], 2, presentWallet())->wallet;

    expect($edited->version)->toBe(2)
        ->and($edited->changeSeq)->toBe(4)
        ->and($replayed->version)->toBe(2)
        ->and($replayed->changeSeq)->toBe(4);
    expectScopeIntact($wallet->id);
});

test('a membership write moves the wallet change_seq but not its version', function () {
    $owner = person();
    $other = person('grace@example.test');
    $wallet = ownedWallet($owner->id);
    $versionBefore = $wallet->fresh()->version;

    addMember($wallet->id, $other->id, 'viewer');

    $after = Wallet::query()->find($wallet->id);
    expect($after->version)->toBe($versionBefore)
        ->and($after->change_seq)->toBe(5);
    expectScopeIntact($wallet->id);
});

test('an ownership transfer and a delete keep every value unique and the wallet highest', function () {
    $owner = person();
    $other = person('grace@example.test');
    $wallet = app(WalletService::class)->save($owner->id, (string) Str::uuid7(), walletValues(), 0, presentWallet())->view->wallet;
    addMember($wallet->id, $other->id, 'editor');
    expectScopeIntact($wallet->id);

    $version = Wallet::query()->find($wallet->id)->version;
    app(WalletService::class)->transferOwnership($owner->id, $wallet->id, $other->id, $version, presentWallet());
    expectScopeIntact($wallet->id);

    $version = Wallet::query()->find($wallet->id)->version;
    app(WalletService::class)->delete($other->id, $wallet->id, $version, presentWallet());
    expectScopeIntact($wallet->id);
});

test('two wallets count independently', function () {
    $user = person();
    $a = ownedWallet($user->id);
    $b = ownedWallet($user->id);

    app(WalletService::class)->patch($user->id, $a->id, ['name' => 'A2'], 1, presentWallet());

    expect(Wallet::query()->find($b->id)->change_seq)->toBe(3)
        ->and(Wallet::query()->find($a->id)->change_seq)->toBe(4);
});

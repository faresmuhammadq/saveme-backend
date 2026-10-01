<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;
use App\Modules\Wallets\Application\Dto\WalletView;
use App\Modules\Wallets\Application\WalletQueryService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Domain\Refusals\WalletFieldImmutable;
use App\Modules\Wallets\Domain\Refusals\WalletNotFound;
use App\Modules\Wallets\Domain\Refusals\WalletVersionConflict;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Saving a wallet (spec 010, US2; FR-002 to FR-006, P-07, P-05 rule 4).
 */

function saveWallet(string $userId, string $walletId, array $changes, int $version): WalletView
{
    return app(WalletService::class)->save($userId, $walletId, walletValues($changes), $version, presentWallet())->view;
}

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000, 'name' => 'Cash', 'icon' => 'wallet', 'color' => '#2E7D32']);
});

test('a stale version is refused with the current wallet and nothing is written', function () {
    saveWallet($this->owner->id, $this->wallet->id, ['name' => 'Renamed'], 1);

    try {
        saveWallet($this->owner->id, $this->wallet->id, ['name' => 'Other'], 1);
        $this->fail('expected a conflict');
    } catch (WalletVersionConflict $conflict) {
        $read = app(WalletQueryService::class)->find($this->owner->id, $this->wallet->id);

        expect($conflict->errorCode)->toBe(ErrorCode::VersionConflict)
            ->and($conflict->extensions['current'])->toBe(presentWallet()($read))
            ->and($conflict->extensions['current']['name'])->toBe('Renamed')
            ->and(Wallet::query()->find($this->wallet->id)->version)->toBe(2);
    }
});

test('an identical write changes neither version nor change_seq', function () {
    $before = Wallet::query()->find($this->wallet->id);

    $view = saveWallet($this->owner->id, $this->wallet->id, [], 1);

    $after = Wallet::query()->find($this->wallet->id);
    expect($view->wallet->version)->toBe($before->version)
        ->and($after->version)->toBe($before->version)
        ->and($after->change_seq)->toBe($before->change_seq);
});

test('a negative resulting balance is accepted', function () {
    $view = saveWallet($this->owner->id, $this->wallet->id, ['opening_balance_minor' => -9000], 1);

    expect($view->wallet->balanceMinor)->toBe(-9000);
});

test('editing a deleted wallet is a conflict carrying the tombstone, never a restore', function () {
    $at = now()->format('Y-m-d H:i:s.uP');
    DB::table('wallets')->where('id', $this->wallet->id)->update(['deleted_at' => $at]);
    DB::table('wallet_members')->where('wallet_id', $this->wallet->id)->update(['deleted_at' => $at]);

    try {
        saveWallet($this->owner->id, $this->wallet->id, ['name' => 'Back'], 1);
        $this->fail('expected a conflict');
    } catch (WalletVersionConflict $conflict) {
        expect($conflict->extensions['current']['deleted_at'])->not->toBeNull();
    }

    expect(Wallet::query()->find($this->wallet->id)->deleted_at)->not->toBeNull();
});

test('the currency cannot change once postings exist, and can before', function () {
    fakePostings(hasAny: true);
    expect(fn () => saveWallet($this->owner->id, $this->wallet->id, ['currency' => 'USD'], 1))
        ->toThrow(function (WalletFieldImmutable $refusal): void {
            expect($refusal->fieldErrors[0]->pointer)->toBe('/currency');
        });

    fakePostings(hasAny: false);
    expect(saveWallet($this->owner->id, $this->wallet->id, ['currency' => 'USD'], 1)->wallet->currency)->toBe('USD');
});

test('the type never changes', function () {
    expect(fn () => saveWallet($this->owner->id, $this->wallet->id, ['type' => 'savings'], 1))
        ->toThrow(function (Refusal $refusal): void {
            expect($refusal->fieldErrors[0]->pointer)->toBe('/type');
        });
});

test('a partial change overlays the stored wallet and never creates', function () {
    $view = app(WalletService::class)->patch($this->owner->id, $this->wallet->id, ['name' => '  Spending '], 1, presentWallet());

    expect($view->wallet->name)->toBe('Spending')
        ->and($view->wallet->icon)->toBe('wallet')
        ->and($view->wallet->version)->toBe(2);

    expect(fn () => app(WalletService::class)->patch($this->owner->id, (string) Str::uuid7(), ['name' => 'x'], 1, presentWallet()))
        ->toThrow(WalletNotFound::class);
});

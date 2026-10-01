<?php

use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Domain\Refusals\WalletVersionConflict;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Facades\DB;

/*
 * The balance cache (spec 010, FR-007; P-05, ADR-0005 rules 2-5).
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
});

test('an opening-balance change moves the balance by the same amount in the same transaction', function () {
    $view = app(WalletService::class)->patch($this->owner->id, $this->wallet->id, ['opening_balance_minor' => 80000], 1, presentWallet());

    expect($view->wallet->balanceMinor)->toBe(80000);
});

test('a failure after the opening balance moved leaves both columns unchanged', function () {
    $real = app(ScopeSequence::class);
    app()->bind(ScopeSequence::class, fn () => new class($real) implements ScopeSequence
    {
        public function __construct(private ScopeSequence $real) {}

        public function nextFor(string $walletId): int
        {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(WalletService::class)->patch($this->owner->id, $this->wallet->id, ['opening_balance_minor' => 80000], 1, presentWallet()))
        ->toThrow(RuntimeException::class);

    $stored = Wallet::query()->find($this->wallet->id);
    expect($stored->opening_balance_minor)->toBe(50000)
        ->and($stored->balance_minor)->toBe(50000)
        ->and($stored->version)->toBe(1);
});

test('the recompute reads the postings, never the stored cache', function () {
    DB::table('wallets')->where('id', $this->wallet->id)->update(['balance_minor' => 123456789]);
    fakePostings(sum: 700);

    $view = app(WalletService::class)->patch($this->owner->id, $this->wallet->id, ['opening_balance_minor' => 1000], 1, presentWallet());

    expect($view->wallet->balanceMinor)->toBe(1700);
});

test('two saves of the same version serialise on the wallet lock: the second conflicts', function () {
    app(WalletService::class)->patch($this->owner->id, $this->wallet->id, ['opening_balance_minor' => 1], 1, presentWallet());

    expect(fn () => app(WalletService::class)->patch($this->owner->id, $this->wallet->id, ['opening_balance_minor' => 2], 1, presentWallet()))
        ->toThrow(WalletVersionConflict::class);

    expect(Wallet::query()->find($this->wallet->id)->opening_balance_minor)->toBe(1);
});

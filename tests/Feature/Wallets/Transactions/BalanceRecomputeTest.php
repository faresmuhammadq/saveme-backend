<?php

use App\Modules\Wallets\Application\Balances\VerifyBalances;
use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Infrastructure\Models\Wallet;
use Illuminate\Support\Str;

/*
 * The cached balance is recomputed from opening + Σ postings in the write's
 * own unit of work (spec 012, US1 S5, R-3; P-05 rule 4, ADR-0005).
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id, ['opening_balance_minor' => 50000, 'balance_minor' => 50000]);
    $this->other = ownedWallet($this->owner->id, ['opening_balance_minor' => 1000, 'balance_minor' => 1000]);
    $this->service = app(TransactionService::class);
});

test('create, edit, delete and restore each leave every touched balance exact', function () {
    $id = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->wallet->id, $id, transferBody($this->other->id, ['amount_minor' => 3000]));
    expect(walletBalance($this->wallet->id))->toBe(47000)->and(walletBalance($this->other->id))->toBe(4000);
    expectLedgerIntact();

    $this->service->patch($this->owner->id, $this->wallet->id, $id, ['amount_minor' => 500], 1, presentTransaction());
    expect(walletBalance($this->wallet->id))->toBe(49500)->and(walletBalance($this->other->id))->toBe(1500);
    expectLedgerIntact();

    $this->service->delete($this->owner->id, $this->wallet->id, $id, 2, presentTransaction());
    expect(walletBalance($this->wallet->id))->toBe(50000)->and(walletBalance($this->other->id))->toBe(1000);
    expectLedgerIntact();

    $this->service->restore($this->owner->id, $this->wallet->id, $id, 3, presentTransaction());
    expect(walletBalance($this->wallet->id))->toBe(49500)->and(walletBalance($this->other->id))->toBe(1500);
    expectLedgerIntact();
});

test('the wallet change_seq is the highest in its scope and its version never moves', function () {
    $id = (string) Str::uuid7();
    saveTransaction($this->owner->id, $this->wallet->id, $id, transactionBody());
    $this->service->patch($this->owner->id, $this->wallet->id, $id, ['amount_minor' => 100], 1, presentTransaction());
    $this->service->delete($this->owner->id, $this->wallet->id, $id, 2, presentTransaction());

    expectTransactionScopeIntact($this->wallet->id);
    expect(Wallet::query()->find($this->wallet->id)->version)->toBe(1);
});

test('the drift check finds nothing after a mix of writes', function () {
    saveTransaction($this->owner->id, $this->wallet->id, (string) Str::uuid7(), transactionBody(['amount_minor' => 1234]));
    saveTransaction($this->owner->id, $this->wallet->id, (string) Str::uuid7(), incomeBody(['amount_minor' => 4321]));
    saveTransaction($this->owner->id, $this->wallet->id, (string) Str::uuid7(), transferBody($this->other->id, ['amount_minor' => 99]));

    expect(app(VerifyBalances::class)->handle())->toBe(0);
});

<?php

use App\Modules\Wallets\Application\Transactions\TransactionService;
use App\Modules\Wallets\Application\WalletQueryService;
use App\Modules\Wallets\Application\WalletService;
use App\Modules\Wallets\Domain\Refusals\WalletFieldImmutable;

/*
 * The wallet's currency locks once any transaction, live or tombstoned,
 * touches it as source or destination (spec 012, US5 S1, FR-019, R-3, A-9).
 */

beforeEach(function () {
    $this->owner = person();
    $this->wallet = ownedWallet($this->owner->id);
    $this->other = ownedWallet($this->owner->id);
});

function currencyLocked(string $userId, string $walletId): bool
{
    return app(WalletQueryService::class)->find($userId, $walletId)->currencyLocked;
}

test('a wallet with no transaction is not locked', function () {
    expect(currencyLocked($this->owner->id, $this->wallet->id))->toBeFalse();
});

test('a live transaction locks its wallet, and a transfer locks both', function () {
    recordTransaction($this->owner->id, $this->wallet->id, transferBody($this->other->id));

    expect(currencyLocked($this->owner->id, $this->wallet->id))->toBeTrue()
        ->and(currencyLocked($this->owner->id, $this->other->id))->toBeTrue();
});

test('a tombstoned transaction still locks', function () {
    $view = recordTransaction($this->owner->id, $this->wallet->id);
    app(TransactionService::class)->delete($this->owner->id, $this->wallet->id, $view->transaction->id, 1, presentTransaction());

    expect(currencyLocked($this->owner->id, $this->wallet->id))->toBeTrue();
});

test('a wallet PUT or PATCH changing a locked currency is IMMUTABLE_FIELD at /currency', function () {
    recordTransaction($this->owner->id, $this->wallet->id);
    $service = app(WalletService::class);

    $save = refusalOf(fn () => $service->save($this->owner->id, $this->wallet->id, walletValues(['type' => 'cash', 'name' => 'x', 'currency' => 'USD']), 1, presentWallet()));
    $patch = refusalOf(fn () => $service->patch($this->owner->id, $this->wallet->id, ['currency' => 'USD'], 1, presentWallet()));

    expect($save)->toBeInstanceOf(WalletFieldImmutable::class)->and(fieldErrorsOf($save))->toBe(['/currency IMMUTABLE_FIELD'])
        ->and(fieldErrorsOf($patch))->toBe(['/currency IMMUTABLE_FIELD']);
});

test('the other wallet keeps its freedom when no transaction touches it', function () {
    recordTransaction($this->owner->id, $this->wallet->id);

    $view = app(WalletService::class)->patch($this->owner->id, $this->other->id, ['currency' => 'USD'], 1, presentWallet());

    expect($view->wallet->currency)->toBe('USD');
});

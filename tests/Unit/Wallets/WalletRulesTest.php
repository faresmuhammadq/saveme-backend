<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Wallets\Domain\Refusals\WalletFieldImmutable;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;
use App\Modules\Wallets\Domain\Wallets\WalletRules;
use App\Modules\Wallets\Domain\Wallets\WalletType;

function aWalletRecord(): WalletRecord
{
    $now = new DateTimeImmutable('2026-10-02T08:00:00Z');

    return new WalletRecord('0192f7a0-1c3d-7abc-9f01-2b3c4d5e6f70', WalletType::Cash, 'Cash', 'EGP', 0, 0, null, null, null, 1, 3, $now, $now, null);
}

test('a type change is refused at /type', function () {
    expect(fn () => WalletRules::assertImmutablesKept(aWalletRecord(), WalletType::Savings, 'EGP', false))
        ->toThrow(function (WalletFieldImmutable $refusal): void {
            expect($refusal->errorCode)->toBe(ErrorCode::ValidationFailed)
                ->and($refusal->fieldErrors[0]->pointer)->toBe('/type')
                ->and($refusal->fieldErrors[0]->code)->toBe(ErrorCode::ImmutableField);
        });
});

test('a currency change is refused at /currency only when locked', function () {
    expect(fn () => WalletRules::assertImmutablesKept(aWalletRecord(), WalletType::Cash, 'USD', true))
        ->toThrow(function (WalletFieldImmutable $refusal): void {
            expect($refusal->fieldErrors[0]->pointer)->toBe('/currency');
        });

    WalletRules::assertImmutablesKept(aWalletRecord(), WalletType::Cash, 'USD', false);
    expect(true)->toBeTrue();
});

test('the unchanged currency passes when locked', function () {
    WalletRules::assertImmutablesKept(aWalletRecord(), WalletType::Cash, 'EGP', true);

    expect(true)->toBeTrue();
});

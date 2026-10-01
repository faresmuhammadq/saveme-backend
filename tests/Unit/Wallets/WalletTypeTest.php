<?php

use App\Modules\Wallets\Domain\Wallets\WalletType;

test('there are six wallet types', function () {
    expect(array_map(fn (WalletType $type): string => $type->value, WalletType::cases()))
        ->toBe(['cash', 'checking', 'savings', 'credit_card', 'loan', 'investment']);
});

test('only a credit card and a loan are liabilities', function () {
    foreach (WalletType::cases() as $type) {
        expect($type->isLiability())->toBe(in_array($type, [WalletType::CreditCard, WalletType::Loan], true));
    }
});

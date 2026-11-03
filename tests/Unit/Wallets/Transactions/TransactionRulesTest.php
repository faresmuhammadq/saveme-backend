<?php

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;
use App\Modules\Wallets\Domain\Transactions\TransactionRecord;
use App\Modules\Wallets\Domain\Transactions\TransactionRules;

/*
 * The lookup-free state rules (spec 012, section 5, R-14): immutables, the
 * kind's shape and the currency. Every violation is collected.
 */

function storedAs(TransactionKind $kind): TransactionRecord
{
    $now = new DateTimeImmutable;

    return new TransactionRecord(
        id: 'tx', walletId: 'wallet-a', kind: $kind, date: '2026-10-01', amountMinor: 100, currency: 'EGP',
        categoryId: $kind === TransactionKind::Transfer ? null : 'category',
        counterWalletId: $kind === TransactionKind::Transfer ? 'wallet-b' : null,
        description: null, memo: null, counterparty: null, cleared: false, createdBy: 'user',
        version: 1, changeSeq: 1, counterChangeSeq: null, createdAt: $now, updatedAt: $now, deletedAt: null,
    );
}

/**
 * @param  array<string, mixed>  $changes
 * @return array{kind: string, currency: string, category_id: ?string, counter_wallet_id: ?string}
 */
function ruleValues(array $changes = []): array
{
    return ['kind' => 'expense', 'currency' => 'EGP', 'category_id' => 'category', 'counter_wallet_id' => null, ...$changes];
}

/**
 * @param  list<FieldError>  $errors
 * @return list<string>
 */
function pointersAndCodes(array $errors): array
{
    return array_map(fn (FieldError $error): string => $error->pointer.' '.$error->code->value, $errors);
}

test('a valid expense, income and transfer have no violation', function () {
    expect(TransactionRules::violations(ruleValues(), null, 'EGP', null))->toBe([])
        ->and(TransactionRules::violations(ruleValues(['kind' => 'income']), null, 'EGP', null))->toBe([])
        ->and(TransactionRules::violations(ruleValues(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => 'wallet-b']), null, 'EGP', 'EGP'))->toBe([]);
});

test('an adjustment needs no category and no counter wallet, and refuses both', function (string $kind) {
    $bare = ruleValues(['kind' => $kind, 'category_id' => null]);

    expect(TransactionRules::violations($bare, null, 'EGP', null))->toBe([])
        ->and(pointersAndCodes(TransactionRules::violations([...$bare, 'category_id' => 'category'], null, 'EGP', null)))->toBe(['/category_id INVALID_ENUM_VALUE'])
        ->and(pointersAndCodes(TransactionRules::violations([...$bare, 'counter_wallet_id' => 'wallet-b'], null, 'EGP', null)))->toBe(['/counter_wallet_id INVALID_ENUM_VALUE']);
})->with(['adjustment_in', 'adjustment_out']);

test('a kind change is refused as immutable', function () {
    $errors = TransactionRules::violations(ruleValues(['kind' => 'income']), storedAs(TransactionKind::Expense), 'EGP', null);

    expect(pointersAndCodes($errors))->toBe(['/kind IMMUTABLE_FIELD']);
});

test('a changed counter wallet is refused as immutable', function () {
    $errors = TransactionRules::violations(ruleValues(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => 'wallet-c']), storedAs(TransactionKind::Transfer), 'EGP', 'EGP');

    expect(pointersAndCodes($errors))->toBe(['/counter_wallet_id IMMUTABLE_FIELD']);
});

test('a currency other than the wallet\'s is a mismatch at /currency', function () {
    expect(pointersAndCodes(TransactionRules::violations(ruleValues(['currency' => 'USD']), null, 'EGP', null)))->toBe(['/currency CURRENCY_MISMATCH']);
});

test('a transfer between currencies is a mismatch at /counter_wallet_id', function () {
    $errors = TransactionRules::violations(ruleValues(['kind' => 'transfer', 'category_id' => null, 'counter_wallet_id' => 'wallet-b']), null, 'EGP', 'USD');

    expect(pointersAndCodes($errors))->toBe(['/counter_wallet_id CURRENCY_MISMATCH']);
});

test('a category on a transfer and a counter wallet on income or expense are invalid enum values', function () {
    $transfer = TransactionRules::violations(ruleValues(['kind' => 'transfer', 'category_id' => 'category', 'counter_wallet_id' => 'wallet-b']), null, 'EGP', 'EGP');
    $expense = TransactionRules::violations(ruleValues(['counter_wallet_id' => 'wallet-b']), null, 'EGP', null);

    expect(pointersAndCodes($transfer))->toBe(['/category_id INVALID_ENUM_VALUE'])
        ->and(pointersAndCodes($expense))->toBe(['/counter_wallet_id INVALID_ENUM_VALUE']);
});

test('a missing category or counter wallet is required', function () {
    expect(pointersAndCodes(TransactionRules::violations(ruleValues(['category_id' => null]), null, 'EGP', null)))->toBe(['/category_id REQUIRED'])
        ->and(pointersAndCodes(TransactionRules::violations(ruleValues(['kind' => 'transfer', 'category_id' => null]), null, 'EGP', null)))->toBe(['/counter_wallet_id REQUIRED']);
});

test('several violations are collected at once', function () {
    $errors = TransactionRules::violations(ruleValues(['kind' => 'income', 'currency' => 'USD', 'category_id' => null]), storedAs(TransactionKind::Expense), 'EGP', null);

    expect(pointersAndCodes($errors))->toBe(['/kind IMMUTABLE_FIELD', '/category_id REQUIRED', '/currency CURRENCY_MISMATCH']);
});

test('the error codes are the catalogued ones', function () {
    expect(ErrorCode::CurrencyMismatch->status())->toBe(422)->and(ErrorCode::CurrencyMismatch->isFieldLevelOnly())->toBeTrue();
});

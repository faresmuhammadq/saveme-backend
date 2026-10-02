<?php

use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Domain\Recurring\OccurrencePlan;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRecord;
use App\Modules\Wallets\Domain\Recurring\OccurrenceRules;
use App\Modules\Wallets\Domain\Recurring\OccurrenceStatus;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;

function occurrenceRecord(string $status = 'scheduled', string $kind = 'expense', ?string $transactionId = null): OccurrenceRecord
{
    $now = new DateTimeImmutable('2026-10-05 10:00:00');

    return new OccurrenceRecord(
        id: '0192f7d1-0000-7000-8000-0000000000b3',
        walletId: '0192f79e-0000-7000-8000-000000000001',
        recurringRuleId: '0192f7d0-0000-7000-8000-0000000000a3',
        ordinal: 2,
        isPayout: false,
        scheduledDate: '2026-11-01',
        date: '2026-11-01',
        status: OccurrenceStatus::from($status),
        kind: TransactionKind::from($kind),
        amountMinor: 450000,
        currency: 'EGP',
        categoryId: $kind === 'transfer' ? null : '0192f7a1-0000-7000-8000-0000000000c5',
        counterWalletId: $kind === 'transfer' ? '0192f79e-0000-7000-8000-000000000002' : null,
        description: 'Rent',
        memo: null,
        counterparty: null,
        edited: false,
        transactionId: $transactionId,
        autoConfirmRefusal: null,
        version: 1,
        changeSeq: 9,
        createdAt: $now,
        updatedAt: $now,
        deletedAt: null,
    );
}

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function occurrenceValues(OccurrenceRecord $stored, array $changes = []): array
{
    return [...$stored->writableValues(), ...$changes];
}

/**
 * @param  list<FieldError>  $errors
 * @return list<string>
 */
function occurrenceErrors(array $errors): array
{
    return array_map(fn (FieldError $error): string => $error->pointer.' '.$error->code->value, $errors);
}

it('allows scheduled to confirmed or skipped, and skipped back to scheduled or on to confirmed', function (string $from, string $to): void {
    $stored = occurrenceRecord($from);
    $values = occurrenceValues($stored, ['status' => $to, 'transaction_id' => $to === 'confirmed' ? '0192f7e0-0000-7000-8000-0000000000f1' : null]);

    expect(OccurrenceRules::violations($values, $stored))->toBe([]);
})->with([
    ['scheduled', 'confirmed'],
    ['scheduled', 'skipped'],
    ['skipped', 'scheduled'],
    ['skipped', 'confirmed'],
]);

it('refuses any change to a confirmed occurrence at the member', function (): void {
    $stored = occurrenceRecord('confirmed', 'expense', '0192f7e0-0000-7000-8000-0000000000f1');

    expect(occurrenceErrors(OccurrenceRules::violations(occurrenceValues($stored, ['status' => 'skipped', 'transaction_id' => null, 'amount_minor' => 1]), $stored)))
        ->toBe(['/status IMMUTABLE_FIELD', '/amount_minor IMMUTABLE_FIELD', '/transaction_id IMMUTABLE_FIELD']);
});

it('requires a transaction id on a confirmation and refuses one otherwise', function (): void {
    $stored = occurrenceRecord();

    expect(occurrenceErrors(OccurrenceRules::violations(occurrenceValues($stored, ['status' => 'confirmed']), $stored)))->toBe(['/transaction_id REQUIRED'])
        ->and(occurrenceErrors(OccurrenceRules::violations(occurrenceValues($stored, ['status' => 'skipped', 'transaction_id' => '0192f7e0-0000-7000-8000-0000000000f1']), $stored)))->toBe(['/transaction_id INVALID_ENUM_VALUE'])
        ->and(occurrenceErrors(OccurrenceRules::violations(occurrenceValues($stored, ['transaction_id' => '0192f7e0-0000-7000-8000-0000000000f1']), $stored)))->toBe(['/transaction_id INVALID_ENUM_VALUE']);
});

it('keeps a transfer\'s category null and a category on the rest', function (): void {
    $transfer = occurrenceRecord('scheduled', 'transfer');
    $expense = occurrenceRecord();

    expect(occurrenceErrors(OccurrenceRules::violations(occurrenceValues($transfer, ['category_id' => '0192f7a1-0000-7000-8000-0000000000c5']), $transfer)))->toBe(['/category_id INVALID_ENUM_VALUE'])
        ->and(occurrenceErrors(OccurrenceRules::violations(occurrenceValues($expense, ['category_id' => null]), $expense)))->toBe(['/category_id REQUIRED']);
});

it('sets edited on a difference from the rule-derived values and clears it on equality', function (): void {
    $plan = new OccurrencePlan(2, false, '2026-11-01', TransactionKind::Expense, 450000, 'EGP', '0192f7a1-0000-7000-8000-0000000000c5', null, 'Rent', null, null);
    $same = ['date' => '2026-11-01', 'amount_minor' => 450000, 'category_id' => '0192f7a1-0000-7000-8000-0000000000c5', 'description' => 'Rent', 'memo' => null, 'counterparty' => null];

    expect($plan->isEditedBy($same))->toBeFalse()
        ->and($plan->isEditedBy([...$same, 'amount_minor' => 61200]))->toBeTrue()
        ->and($plan->isEditedBy([...$same, 'date' => '2026-11-03']))->toBeTrue()
        ->and($plan->isEditedBy([...$same, 'memo' => 'late']))->toBeTrue()
        ->and($plan->isEditedBy($same))->toBeFalse();
});

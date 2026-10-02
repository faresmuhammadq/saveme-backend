<?php

use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Domain\Recurring\AmountBasis;
use App\Modules\Wallets\Domain\Recurring\Frequency;
use App\Modules\Wallets\Domain\Recurring\Preset;
use App\Modules\Wallets\Domain\Recurring\RecurringRuleRecord;
use App\Modules\Wallets\Domain\Recurring\RuleStatus;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function plainRuleValues(array $changes = []): array
{
    return [
        'preset' => 'custom',
        'status' => 'active',
        'kind' => 'expense',
        'amount_minor' => 450000,
        'amount_basis' => 'per_occurrence',
        'currency' => 'EGP',
        'category_id' => '0192f7a1-0000-7000-8000-0000000000c5',
        'counter_wallet_id' => null,
        'description' => 'Rent',
        'memo' => null,
        'counterparty' => null,
        'auto_confirm' => true,
        'frequency' => 'monthly',
        'interval' => 1,
        'anchor_day' => 1,
        'start_date' => '2026-11-01',
        'end_date' => null,
        'occurrence_count' => null,
        'payout_round' => null,
        'payout_category_id' => null,
        ...$changes,
    ];
}

function ruleRecord(array $values = []): RecurringRuleRecord
{
    $values = plainRuleValues($values);
    $now = new DateTimeImmutable('2026-10-05 10:00:00');

    return new RecurringRuleRecord(
        id: '0192f7d0-0000-7000-8000-0000000000a3',
        walletId: '0192f79e-0000-7000-8000-000000000001',
        preset: Preset::from($values['preset']),
        status: RuleStatus::from($values['status']),
        kind: TransactionKind::from($values['kind']),
        amountMinor: $values['amount_minor'],
        amountBasis: AmountBasis::from($values['amount_basis']),
        currency: $values['currency'],
        categoryId: $values['category_id'],
        counterWalletId: $values['counter_wallet_id'],
        description: $values['description'],
        memo: $values['memo'],
        counterparty: $values['counterparty'],
        autoConfirm: $values['auto_confirm'],
        autoConfirmFrom: '2026-10-05',
        frequency: Frequency::from($values['frequency']),
        interval: $values['interval'],
        anchorDay: $values['anchor_day'],
        startDate: $values['start_date'],
        endDate: $values['end_date'],
        occurrenceCount: $values['occurrence_count'],
        payoutRound: $values['payout_round'],
        payoutCategoryId: $values['payout_category_id'],
        timezone: 'Africa/Cairo',
        createdBy: '0192f79e-0000-7000-8000-0000000000aa',
        version: 1,
        changeSeq: 5,
        createdAt: $now,
        updatedAt: $now,
        deletedAt: null,
    );
}

/**
 * @param  list<FieldError>  $errors
 * @return list<string>
 */
function ruleErrors(array $errors): array
{
    return array_map(fn (FieldError $error): string => $error->pointer.' '.$error->code->value, $errors);
}

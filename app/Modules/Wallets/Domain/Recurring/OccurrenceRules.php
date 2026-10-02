<?php

namespace App\Modules\Wallets\Domain\Recurring;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;

/**
 * The state rules of an occurrence write that need no lookup (spec 014,
 * FR-014, FR-015): a confirmed occurrence never changes, a transaction
 * identifier comes with a confirmation and only then, and a transfer's
 * category stays null. `scheduled` → `confirmed` | `skipped`; `skipped` →
 * `scheduled` | `confirmed`; no other transition leaves a state. Every
 * violation is collected, one entry per member.
 *
 * @phpstan-import-type Values from OccurrenceRecord
 */
final class OccurrenceRules
{
    /**
     * @param  Values  $values  the full writable values wanted
     * @return list<FieldError>
     */
    public static function violations(array $values, OccurrenceRecord $stored): array
    {
        $errors = [];
        $add = function (string $member, ErrorCode $code) use (&$errors): void {
            $errors['/'.$member] ??= new FieldError('/'.$member, $code);
        };

        if ($stored->status === OccurrenceStatus::Confirmed) {
            foreach ($stored->writableValues() as $member => $value) {
                if ($values[$member] !== $value) {
                    $add($member, ErrorCode::ImmutableField);
                }
            }

            return array_values($errors);
        }

        $confirming = $values['status'] === OccurrenceStatus::Confirmed->value;

        if ($confirming && $values['transaction_id'] === null) {
            $add('transaction_id', ErrorCode::Required);
        } elseif (! $confirming && $values['transaction_id'] !== null) {
            $add('transaction_id', ErrorCode::InvalidEnumValue);
        }

        if ($stored->kind === TransactionKind::Transfer) {
            if ($values['category_id'] !== null) {
                $add('category_id', ErrorCode::InvalidEnumValue);
            }
        } elseif ($values['category_id'] === null) {
            $add('category_id', ErrorCode::Required);
        }

        return array_values($errors);
    }
}

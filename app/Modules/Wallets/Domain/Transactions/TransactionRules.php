<?php

namespace App\Modules\Wallets\Domain\Transactions;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;

/**
 * The state rules of a transaction write that need no lookup (spec 012,
 * section 5): kind and counter wallet never change, the kind decides whether
 * a category, a counter wallet or neither (an adjustment) is meant, and the
 * currency is the wallet's.
 * Every violation is collected, one entry each, so a client fixes them all at
 * once. Rules that need a lookup (does the category exist, is the caller a
 * member of the destination) are the application's and add to this list.
 */
final class TransactionRules
{
    /**
     * @param  array{kind: string, currency: string, category_id: ?string, counter_wallet_id: ?string}  $values  the full values wanted
     * @param  string  $walletCurrency  the home wallet's currency
     * @param  ?string  $counterCurrency  the destination's currency, when it is known
     * @return list<FieldError>
     */
    public static function violations(array $values, ?TransactionRecord $stored, string $walletCurrency, ?string $counterCurrency): array
    {
        $errors = [];
        $kind = TransactionKind::from($values['kind']);

        if ($stored !== null && $kind !== $stored->kind) {
            $errors[] = new FieldError('/kind', ErrorCode::ImmutableField);
        } elseif ($stored?->isTransfer() === true && $values['counter_wallet_id'] !== $stored->counterWalletId) {
            $errors[] = new FieldError('/counter_wallet_id', ErrorCode::ImmutableField);
        }

        if ($kind->isAdjustment()) {
            if ($values['category_id'] !== null) {
                $errors[] = new FieldError('/category_id', ErrorCode::InvalidEnumValue);
            }

            if ($values['counter_wallet_id'] !== null) {
                $errors[] = new FieldError('/counter_wallet_id', ErrorCode::InvalidEnumValue);
            }
        } elseif ($kind === TransactionKind::Transfer) {
            if ($values['category_id'] !== null) {
                $errors[] = new FieldError('/category_id', ErrorCode::InvalidEnumValue);
            }

            if ($values['counter_wallet_id'] === null) {
                $errors[] = new FieldError('/counter_wallet_id', ErrorCode::Required);
            }
        } else {
            if ($values['category_id'] === null) {
                $errors[] = new FieldError('/category_id', ErrorCode::Required);
            }

            if ($values['counter_wallet_id'] !== null) {
                $errors[] = new FieldError('/counter_wallet_id', ErrorCode::InvalidEnumValue);
            }
        }

        if ($values['currency'] !== $walletCurrency) {
            $errors[] = new FieldError('/currency', ErrorCode::CurrencyMismatch);
        }

        if ($kind === TransactionKind::Transfer && $counterCurrency !== null && $counterCurrency !== $walletCurrency) {
            $errors[] = new FieldError('/counter_wallet_id', ErrorCode::CurrencyMismatch);
        }

        return $errors;
    }
}

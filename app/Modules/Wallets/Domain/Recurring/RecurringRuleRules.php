<?php

namespace App\Modules\Wallets\Domain\Recurring;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\FieldError;
use App\Modules\Shared\Domain\Money\Money;
use App\Modules\Wallets\Domain\Transactions\TransactionKind;

/**
 * The state rules of a recurring rule write that need no lookup (spec 014,
 * FR-002 to FR-007, data-model "Validation summary"): immutable members,
 * members frozen once an occurrence is resolved, an ended rule, the shape of
 * each preset and the shape of the schedule. Every violation is collected,
 * one entry per member, so a client fixes them all at once; the first rule to
 * name a member wins. Rules that need a lookup (does the category exist, is
 * the destination the caller's) belong to the application.
 *
 * @phpstan-import-type Values from RecurringRuleRecord
 */
final class RecurringRuleRules
{
    private const IMMUTABLE = ['preset', 'kind', 'currency', 'counter_wallet_id'];

    private const FROZEN_ONCE_RESOLVED = ['frequency', 'interval', 'anchor_day', 'start_date', 'amount_basis', 'payout_round'];

    private const PRESET_COUNT_MIN = 2;

    private const PRESET_COUNT_MAX = 120;

    /**
     * @param  Values  $values  the full values wanted
     * @param  array{ordinal: ?int, scheduled_date: ?string}|null  $resolved  the latest resolved round of the rule, null when none is resolved
     * @return list<FieldError>
     */
    public static function violations(array $values, ?RecurringRuleRecord $stored, ?array $resolved): array
    {
        $errors = [];
        $add = function (string $member, ErrorCode $code) use (&$errors): void {
            $errors['/'.$member] ??= new FieldError('/'.$member, $code);
        };

        if ($stored === null) {
            if ($values['status'] === RuleStatus::Ended->value) {
                $add('status', ErrorCode::InvalidEnumValue);
            }
        } else {
            $current = $stored->writableValues();

            if ($stored->status === RuleStatus::Ended) {
                foreach ($current as $member => $value) {
                    if ($values[$member] !== $value) {
                        $add($member, ErrorCode::ImmutableField);
                    }
                }

                return array_values($errors);
            }

            $frozen = self::IMMUTABLE;

            if ($resolved !== null) {
                $frozen = [...$frozen, ...self::FROZEN_ONCE_RESOLVED];

                if ($stored->preset === Preset::Gameya || $stored->amountBasis === AmountBasis::Total) {
                    $frozen[] = 'occurrence_count';
                    $frozen[] = 'amount_minor';
                }
            }

            foreach ($frozen as $member) {
                if ($values[$member] !== $current[$member]) {
                    $add($member, ErrorCode::ImmutableField);
                }
            }
        }

        self::kindShape($values, $add);
        self::scheduleShape($values, $add);
        self::presetShape($values, $add);

        if ($resolved !== null) {
            if ($values['end_date'] !== null && $resolved['scheduled_date'] !== null && $values['end_date'] < $resolved['scheduled_date']) {
                $add('end_date', ErrorCode::OutOfRange);
            }

            if ($values['occurrence_count'] !== null && $resolved['ordinal'] !== null && $values['occurrence_count'] < $resolved['ordinal']) {
                $add('occurrence_count', ErrorCode::OutOfRange);
            }
        }

        return array_values($errors);
    }

    /**
     * A category on income and expense, a destination on a transfer.
     *
     * @param  Values  $values
     * @param  callable(string, ErrorCode): void  $add
     */
    private static function kindShape(array $values, callable $add): void
    {
        if ($values['kind'] === TransactionKind::Transfer->value) {
            if ($values['category_id'] !== null) {
                $add('category_id', ErrorCode::InvalidEnumValue);
            }

            if ($values['counter_wallet_id'] === null) {
                $add('counter_wallet_id', ErrorCode::Required);
            }

            return;
        }

        if ($values['category_id'] === null) {
            $add('category_id', ErrorCode::Required);
        }

        if ($values['counter_wallet_id'] !== null) {
            $add('counter_wallet_id', ErrorCode::InvalidEnumValue);
        }
    }

    /**
     * The anchor day, the end members and a total split.
     *
     * @param  Values  $values
     * @param  callable(string, ErrorCode): void  $add
     */
    private static function scheduleShape(array $values, callable $add): void
    {
        if (Frequency::from($values['frequency'])->usesAnchorDay()) {
            if ($values['anchor_day'] === null) {
                $add('anchor_day', ErrorCode::Required);
            }
        } elseif ($values['anchor_day'] !== null) {
            $add('anchor_day', ErrorCode::InvalidEnumValue);
        }

        if ($values['end_date'] !== null && $values['occurrence_count'] !== null) {
            $add('occurrence_count', ErrorCode::InvalidEnumValue);
        }

        if ($values['end_date'] !== null && $values['end_date'] < $values['start_date']) {
            $add('end_date', ErrorCode::OutOfRange);
        }

        if ($values['amount_basis'] === AmountBasis::Total->value) {
            if ($values['occurrence_count'] === null) {
                $add('amount_basis', ErrorCode::InvalidEnumValue);
            } elseif ($values['amount_minor'] < $values['occurrence_count']) {
                $add('amount_minor', ErrorCode::OutOfRange);
            }
        }
    }

    /**
     * Gam'eya, installment and custom each constrain the rule's shape.
     *
     * @param  Values  $values
     * @param  callable(string, ErrorCode): void  $add
     */
    private static function presetShape(array $values, callable $add): void
    {
        $preset = Preset::from($values['preset']);

        if ($preset === Preset::Custom) {
            self::noPayout($values, $add);

            return;
        }

        if ($values['frequency'] !== Frequency::Monthly->value) {
            $add('frequency', ErrorCode::InvalidEnumValue);
        }

        if ($values['interval'] !== 1) {
            $add('interval', ErrorCode::InvalidEnumValue);
        }

        if ($values['end_date'] !== null) {
            $add('end_date', ErrorCode::InvalidEnumValue);
        }

        $count = $values['occurrence_count'];

        if ($count === null) {
            $add('occurrence_count', ErrorCode::Required);
        } elseif ($count < self::PRESET_COUNT_MIN || $count > self::PRESET_COUNT_MAX) {
            $add('occurrence_count', ErrorCode::OutOfRange);
        }

        if ($preset === Preset::Installment) {
            if ($values['kind'] === TransactionKind::Income->value) {
                $add('kind', ErrorCode::InvalidEnumValue);
            }

            self::noPayout($values, $add);

            return;
        }

        if ($values['kind'] !== TransactionKind::Expense->value) {
            $add('kind', ErrorCode::InvalidEnumValue);
        }

        if ($values['payout_round'] === null) {
            $add('payout_round', ErrorCode::Required);
        } elseif ($count !== null && $values['payout_round'] > $count) {
            $add('occurrence_count', ErrorCode::OutOfRange);
        }

        if ($values['payout_category_id'] === null) {
            $add('payout_category_id', ErrorCode::Required);
        }

        $payout = OccurrenceAmounts::payout(AmountBasis::from($values['amount_basis']), $values['amount_minor'], $count);

        if ($payout > Money::MAX_MINOR) {
            $add('amount_minor', ErrorCode::OutOfRange);
        }
    }

    /**
     * @param  Values  $values
     * @param  callable(string, ErrorCode): void  $add
     */
    private static function noPayout(array $values, callable $add): void
    {
        if ($values['payout_round'] !== null) {
            $add('payout_round', ErrorCode::InvalidEnumValue);
        }

        if ($values['payout_category_id'] !== null) {
            $add('payout_category_id', ErrorCode::InvalidEnumValue);
        }
    }
}

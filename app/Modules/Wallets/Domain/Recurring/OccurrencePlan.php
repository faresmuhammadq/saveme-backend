<?php

namespace App\Modules\Wallets\Domain\Recurring;

use App\Modules\Wallets\Domain\Transactions\TransactionKind;

/**
 * What the schedule says an occurrence is: its key and the values a rule
 * gives it (spec 014, FR-012). A materialised row snapshots these; a rule
 * edit recomputes them for the pending, unedited rows.
 */
final readonly class OccurrencePlan
{
    public function __construct(
        public int $ordinal,
        public bool $isPayout,
        public string $scheduledDate,
        public TransactionKind $kind,
        public int $amountMinor,
        public string $currency,
        public ?string $categoryId,
        public ?string $counterWalletId,
        public ?string $description,
        public ?string $memo,
        public ?string $counterparty,
    ) {}

    /**
     * The plan for one round of the rule, or its payout.
     */
    public static function forRound(RecurringRuleRecord $rule, int $ordinal, string $scheduledDate, bool $isPayout): self
    {
        if ($isPayout) {
            return new self(
                ordinal: $ordinal,
                isPayout: true,
                scheduledDate: $scheduledDate,
                kind: TransactionKind::Income,
                amountMinor: OccurrenceAmounts::payout($rule->amountBasis, $rule->amountMinor, $rule->occurrenceCount),
                currency: $rule->currency,
                categoryId: $rule->payoutCategoryId,
                counterWalletId: null,
                description: $rule->description,
                memo: $rule->memo,
                counterparty: $rule->counterparty,
            );
        }

        return new self(
            ordinal: $ordinal,
            isPayout: false,
            scheduledDate: $scheduledDate,
            kind: $rule->kind,
            amountMinor: OccurrenceAmounts::forRound($rule->amountBasis, $rule->amountMinor, $rule->currency, $rule->occurrenceCount, $ordinal),
            currency: $rule->currency,
            categoryId: $rule->categoryId,
            counterWalletId: $rule->counterWalletId,
            description: $rule->description,
            memo: $rule->memo,
            counterparty: $rule->counterparty,
        );
    }

    /**
     * Whether a stored occurrence's writable values differ from what the
     * rule gives it, which is what `edited` means (FR-014).
     *
     * @param  array{date: string, amount_minor: int, category_id: ?string, description: ?string, memo: ?string, counterparty: ?string}  $values
     */
    public function isEditedBy(array $values): bool
    {
        return $values['date'] !== $this->scheduledDate
            || $values['amount_minor'] !== $this->amountMinor
            || $values['category_id'] !== $this->categoryId
            || $values['description'] !== $this->description
            || $values['memo'] !== $this->memo
            || $values['counterparty'] !== $this->counterparty;
    }
}

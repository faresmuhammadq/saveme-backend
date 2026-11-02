<?php

namespace App\Modules\Wallets\Http\Resources;

use App\Modules\Wallets\Application\Transactions\Dto\TransactionView;

/**
 * A Transaction on the wire (spec 012, section 5): the syncable envelope, the
 * wallet scope key (the source, for a transfer), and the writable members.
 * Never the postings and never `counter_change_seq` (P-04 rule 6, ADR-0015).
 * `recurring_rule_id` and `occurrence_id` are the read-only links of a
 * transaction an occurrence produced, null otherwise (spec 014, FR-024).
 */
final class TransactionResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(TransactionView $view): array
    {
        $transaction = $view->transaction;

        return [
            'id' => $transaction->id,
            'wallet_id' => $transaction->walletId,
            'version' => $transaction->version,
            'change_seq' => $transaction->changeSeq,
            'created_at' => Instants::format($transaction->createdAt),
            'updated_at' => Instants::format($transaction->updatedAt),
            'deleted_at' => Instants::format($transaction->deletedAt),
            'kind' => $transaction->kind->value,
            'date' => $transaction->date,
            'amount_minor' => $transaction->amountMinor,
            'currency' => $transaction->currency,
            'category_id' => $transaction->categoryId,
            'counter_wallet_id' => $transaction->counterWalletId,
            'description' => $transaction->description,
            'memo' => $transaction->memo,
            'counterparty' => $transaction->counterparty,
            'cleared' => $transaction->cleared,
            'created_by' => $transaction->createdBy,
            'recurring_rule_id' => $transaction->recurringRuleId,
            'occurrence_id' => $transaction->occurrenceId,
            'shared_budget_id' => $transaction->sharedBudgetId,
        ];
    }
}

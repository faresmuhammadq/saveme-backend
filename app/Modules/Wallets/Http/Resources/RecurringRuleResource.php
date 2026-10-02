<?php

namespace App\Modules\Wallets\Http\Resources;

use App\Modules\Wallets\Application\Recurring\Dto\RuleView;

/**
 * A Recurring Rule on the wire (spec 014, section 5): the syncable envelope,
 * the wallet scope key (the source, for a transfer) and every member of
 * `RecurringRule` in `recurring.yaml`. Never `auto_confirm_from`, which is
 * internal.
 */
final class RecurringRuleResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(RuleView $view): array
    {
        $rule = $view->rule;

        return [
            'id' => $rule->id,
            'wallet_id' => $rule->walletId,
            'version' => $rule->version,
            'change_seq' => $rule->changeSeq,
            'created_at' => Instants::format($rule->createdAt),
            'updated_at' => Instants::format($rule->updatedAt),
            'deleted_at' => Instants::format($rule->deletedAt),
            'preset' => $rule->preset->value,
            'status' => $rule->status->value,
            'kind' => $rule->kind->value,
            'amount_minor' => $rule->amountMinor,
            'amount_basis' => $rule->amountBasis->value,
            'currency' => $rule->currency,
            'category_id' => $rule->categoryId,
            'counter_wallet_id' => $rule->counterWalletId,
            'description' => $rule->description,
            'memo' => $rule->memo,
            'counterparty' => $rule->counterparty,
            'auto_confirm' => $rule->autoConfirm,
            'frequency' => $rule->frequency->value,
            'interval' => $rule->interval,
            'anchor_day' => $rule->anchorDay,
            'start_date' => $rule->startDate,
            'end_date' => $rule->endDate,
            'occurrence_count' => $rule->occurrenceCount,
            'payout_round' => $rule->payoutRound,
            'payout_category_id' => $rule->payoutCategoryId,
            'timezone' => $rule->timezone,
            'created_by' => $rule->createdBy,
        ];
    }
}

<?php

namespace App\Modules\Wallets\Http\Resources;

use App\Modules\Wallets\Application\Recurring\Dto\OccurrenceView;

/**
 * An Occurrence on the wire (spec 014, section 5): the syncable envelope, the
 * wallet scope key and every member of `Occurrence` in `recurring.yaml`.
 */
final class OccurrenceResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(OccurrenceView $view): array
    {
        $occurrence = $view->occurrence;

        return [
            'id' => $occurrence->id,
            'wallet_id' => $occurrence->walletId,
            'version' => $occurrence->version,
            'change_seq' => $occurrence->changeSeq,
            'created_at' => Instants::format($occurrence->createdAt),
            'updated_at' => Instants::format($occurrence->updatedAt),
            'deleted_at' => Instants::format($occurrence->deletedAt),
            'recurring_rule_id' => $occurrence->recurringRuleId,
            'ordinal' => $occurrence->ordinal,
            'is_payout' => $occurrence->isPayout,
            'scheduled_date' => $occurrence->scheduledDate,
            'date' => $occurrence->date,
            'status' => $occurrence->status->value,
            'kind' => $occurrence->kind->value,
            'amount_minor' => $occurrence->amountMinor,
            'currency' => $occurrence->currency,
            'category_id' => $occurrence->categoryId,
            'counter_wallet_id' => $occurrence->counterWalletId,
            'description' => $occurrence->description,
            'memo' => $occurrence->memo,
            'counterparty' => $occurrence->counterparty,
            'edited' => $occurrence->edited,
            'transaction_id' => $occurrence->transactionId,
            'auto_confirm_refusal' => $occurrence->autoConfirmRefusal?->value,
        ];
    }
}

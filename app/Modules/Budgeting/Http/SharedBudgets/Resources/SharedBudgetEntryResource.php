<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Resources;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetEntryRecord;
use App\Modules\Budgeting\Http\Resources\Instants;

/**
 * A Shared Budget Entry on the wire (ADR-0027 rule 6): the author, category,
 * date, amount and note. No transaction id, wallet, balance, memo or
 * counterparty.
 */
final class SharedBudgetEntryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SharedBudgetEntryRecord $entry): array
    {
        return [
            'id' => $entry->id,
            'shared_budget_id' => $entry->sharedBudgetId,
            'version' => $entry->version,
            'change_seq' => $entry->changeSeq,
            'created_at' => Instants::format($entry->createdAt),
            'updated_at' => Instants::format($entry->updatedAt),
            'deleted_at' => Instants::format($entry->deletedAt),
            'created_by' => $entry->createdBy,
            'category_id' => $entry->categoryId,
            'date' => $entry->date,
            'amount_minor' => $entry->amountMinor,
            'description' => $entry->description,
            'detached_at' => Instants::format($entry->detachedAt),
        ];
    }
}

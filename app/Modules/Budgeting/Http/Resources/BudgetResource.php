<?php

namespace App\Modules\Budgeting\Http\Resources;

use App\Modules\Budgeting\Domain\BudgetRecord;

/**
 * A Budget on the wire (spec 015, section 5): the syncable envelope, the
 * owner and the budget's own members, exactly as the `Budget` schema.
 */
final class BudgetResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(BudgetRecord $budget): array
    {
        return [
            'id' => $budget->id,
            'user_id' => $budget->userId,
            'version' => $budget->version,
            'change_seq' => $budget->changeSeq,
            'created_at' => Instants::format($budget->createdAt),
            'updated_at' => Instants::format($budget->updatedAt),
            'deleted_at' => Instants::format($budget->deletedAt),
            'category_id' => $budget->categoryId,
            'period_type' => $budget->periodType->value,
            'amount_minor' => $budget->amountMinor,
            'currency' => $budget->currency,
            'archived_at' => Instants::format($budget->archivedAt),
        ];
    }
}

<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Resources;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetRecord;
use App\Modules\Budgeting\Http\Resources\Instants;

/**
 * A Shared Budget on the wire: the syncable envelope and its own members.
 * The scope's purge horizon is server-internal.
 */
final class SharedBudgetResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SharedBudgetRecord $budget): array
    {
        return [
            'id' => $budget->id,
            'owner_id' => $budget->ownerId,
            'version' => $budget->version,
            'change_seq' => $budget->changeSeq,
            'created_at' => Instants::format($budget->createdAt),
            'updated_at' => Instants::format($budget->updatedAt),
            'deleted_at' => Instants::format($budget->deletedAt),
            'name' => $budget->name,
            'currency' => $budget->currency,
            'start_day' => $budget->startDay,
            'timezone' => $budget->timezone,
            'limit_minor' => $budget->limitMinor,
            'archived_at' => Instants::format($budget->archivedAt),
            'limit_history' => $budget->limitHistory,
            'start_day_history' => $budget->startDayHistory,
        ];
    }
}

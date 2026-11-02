<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Resources;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetLineRecord;
use App\Modules\Budgeting\Http\Resources\Instants;

/**
 * A Shared Budget Line on the wire, with the display fields every member
 * needs to render a person's category.
 */
final class SharedBudgetLineResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SharedBudgetLineRecord $line): array
    {
        return [
            'id' => $line->id,
            'shared_budget_id' => $line->sharedBudgetId,
            'version' => $line->version,
            'change_seq' => $line->changeSeq,
            'created_at' => Instants::format($line->createdAt),
            'updated_at' => Instants::format($line->updatedAt),
            'deleted_at' => Instants::format($line->deletedAt),
            'category_id' => $line->categoryId,
            'limit_minor' => $line->limitMinor,
            'name' => $line->name,
            'icon' => $line->icon,
            'color' => $line->color,
            'limit_history' => $line->limitHistory,
        ];
    }
}

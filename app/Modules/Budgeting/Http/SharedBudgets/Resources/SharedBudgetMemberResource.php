<?php

namespace App\Modules\Budgeting\Http\SharedBudgets\Resources;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;
use App\Modules\Budgeting\Http\Resources\Instants;

/**
 * A Shared Budget Member on the wire. `member_change_seq` is server-internal
 * (ADR-0017 rule 2); a person with no name left reads as null, which clients
 * show as "Former member" (ADR-0024 rule 3).
 */
final class SharedBudgetMemberResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SharedBudgetMemberRecord $member, ?string $displayName): array
    {
        return [
            'id' => $member->id,
            'shared_budget_id' => $member->sharedBudgetId,
            'version' => $member->version,
            'change_seq' => $member->changeSeq,
            'created_at' => Instants::format($member->createdAt),
            'updated_at' => Instants::format($member->updatedAt),
            'deleted_at' => Instants::format($member->deletedAt),
            'user_id' => $member->userId,
            'display_name' => $displayName,
            'role' => $member->role->value,
            'read_only' => $member->readOnly,
        ];
    }
}

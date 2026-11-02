<?php

namespace App\Modules\Sharing\Http\Resources;

use App\Modules\Budgeting\Domain\SharedBudgets\SharedBudgetMemberRecord;

/**
 * The membership an accepted Shared Budget invitation made, as the budget's
 * feed renders a Shared Budget Member.
 */
final class SharedBudgetMembershipResource
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

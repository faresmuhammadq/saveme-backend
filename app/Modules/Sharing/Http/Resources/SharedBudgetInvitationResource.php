<?php

namespace App\Modules\Sharing\Http\Resources;

use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRecord;

/**
 * A Shared Budget Invitation on the wire (ADR-0027): the syncable envelope,
 * its budget and its own members. The token hash and the responder never
 * appear.
 */
final class SharedBudgetInvitationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SharedBudgetInvitationRecord $invitation): array
    {
        return [
            'id' => $invitation->id,
            'shared_budget_id' => $invitation->sharedBudgetId,
            'version' => $invitation->version,
            'change_seq' => $invitation->changeSeq,
            'created_at' => Instants::format($invitation->createdAt),
            'updated_at' => Instants::format($invitation->updatedAt),
            'deleted_at' => Instants::format($invitation->deletedAt),
            'invited_by' => $invitation->invitedBy,
            'status' => $invitation->status->value,
            'expires_at' => Instants::format($invitation->expiresAt),
        ];
    }
}

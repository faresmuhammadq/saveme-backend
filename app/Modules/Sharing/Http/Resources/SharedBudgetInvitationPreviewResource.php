<?php

namespace App\Modules\Sharing\Http\Resources;

use App\Modules\Sharing\Application\Dto\SharedBudgetInvitationPreview;

/**
 * What a Shared Budget token offers, for the confirmation screen: the budget,
 * its owner and what joining shows. No amount, wallet or address.
 */
final class SharedBudgetInvitationPreviewResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(SharedBudgetInvitationPreview $preview): array
    {
        return [
            'invitation_id' => $preview->invitationId,
            'shared_budget_id' => $preview->sharedBudgetId,
            'name' => $preview->name,
            'currency' => $preview->currency,
            'owner_display_name' => $preview->ownerDisplayName,
            'member_count' => $preview->memberCount,
            'expires_at' => Instants::format($preview->expiresAt),
            'already_member' => $preview->alreadyMember,
        ];
    }
}

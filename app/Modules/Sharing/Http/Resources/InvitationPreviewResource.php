<?php

namespace App\Modules\Sharing\Http\Resources;

use App\Modules\Sharing\Application\Dto\InvitationPreview;

/**
 * What a token offers, as the `InvitationPreview` schema: no amount, no
 * balance, no address.
 */
final class InvitationPreviewResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(InvitationPreview $preview): array
    {
        return [
            'invitation_id' => $preview->invitationId,
            'wallet_id' => $preview->walletId,
            'wallet_name' => $preview->walletName,
            'role' => $preview->role->value,
            'invited_by_display_name' => $preview->invitedByDisplayName,
            'expires_at' => Instants::format($preview->expiresAt),
            'already_member' => $preview->alreadyMember,
        ];
    }
}

<?php

namespace App\Modules\Sharing\Http\Resources;

use App\Modules\Sharing\Application\Dto\InvitationView;

/**
 * An Invitation on the wire (spec 016, section 5): the syncable envelope, the
 * wallet scope key and the invitation's own members, exactly as the
 * `Invitation` schema. `invitee_email` is the opened address of a pending
 * email invitation, otherwise null; the token hash, the lookup value and the
 * responder never appear.
 */
final class InvitationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(InvitationView $view): array
    {
        $invitation = $view->invitation;

        return [
            'id' => $invitation->id,
            'wallet_id' => $invitation->walletId,
            'version' => $invitation->version,
            'change_seq' => $invitation->changeSeq,
            'created_at' => Instants::format($invitation->createdAt),
            'updated_at' => Instants::format($invitation->updatedAt),
            'deleted_at' => Instants::format($invitation->deletedAt),
            'role' => $invitation->role->value,
            'channel' => $invitation->channel->value,
            'invitee_email' => $view->inviteeEmail,
            'invited_by' => $invitation->invitedBy,
            'status' => $invitation->status->value,
            'expires_at' => Instants::format($invitation->expiresAt),
        ];
    }
}

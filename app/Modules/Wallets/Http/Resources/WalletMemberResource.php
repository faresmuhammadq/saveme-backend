<?php

namespace App\Modules\Wallets\Http\Resources;

use App\Modules\Wallets\Application\Dto\MemberView;

/**
 * A Wallet Member on the wire: the syncable envelope, the wallet scope key,
 * the member's user id, display name and role. No address, no other attribute.
 */
final class WalletMemberResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(MemberView $view): array
    {
        $member = $view->member;

        return [
            'id' => $member->id,
            'wallet_id' => $member->walletId,
            'version' => $member->version,
            'change_seq' => $member->changeSeq,
            'created_at' => Instants::format($member->createdAt),
            'updated_at' => Instants::format($member->updatedAt),
            'deleted_at' => Instants::format($member->deletedAt),
            'member_user_id' => $member->userId,
            'display_name' => $view->displayName,
            'role' => $member->role->value,
        ];
    }
}

<?php

namespace App\Modules\Sharing\Http\Resources;

use App\Modules\Wallets\Application\Dto\MemberView;

/**
 * A Wallet Member on the wire, member for member as the member list renders
 * it (Wallets' WalletMemberResource; Sharing may not import another module's
 * Http layer, P-02). A contract test pins the two together.
 */
final class MemberResource
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

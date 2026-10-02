<?php

namespace App\Modules\Sharing\Domain;

/**
 * The owner's `sharing.max_members_per_wallet` arithmetic (spec 016, R-7).
 * The count is the owner, plus active members, plus live pending invitations;
 * a null quota is unlimited.
 */
final class MemberQuota
{
    /**
     * Whether one more invitation fits: members + pending + 1 within quota.
     *
     * @param  int  $active  active members, the owner included
     * @param  int  $livePending  pending invitations that have not expired
     */
    public static function canInvite(int $active, int $livePending, ?int $quota): bool
    {
        return $quota === null || $active + $livePending + 1 <= $quota;
    }

    /**
     * Whether an invitation can be accepted: members + the wallet's other
     * pending invitations + this one within quota.
     *
     * @param  int  $otherPending  live pending invitations other than the one being accepted
     */
    public static function canAccept(int $active, int $otherPending, ?int $quota): bool
    {
        return $quota === null || $active + $otherPending + 1 <= $quota;
    }

    /**
     * Whether a viewer may be raised to editor: the plan shares at all and
     * the wallet is not above its quota (a downgrade must not be undone by
     * re-promoting past it).
     */
    public static function canPromote(int $active, ?int $quota, bool $sharingEnabled): bool
    {
        return $sharingEnabled && ($quota === null || $active <= $quota);
    }
}

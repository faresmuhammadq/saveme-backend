<?php

namespace App\Modules\Wallets\Domain\Activity;

/**
 * What was done, in the vocabulary of the `ActivityEntry` contract (spec 016,
 * ADR-0020). Clients skip an action they do not know, so a case may be added.
 */
enum ActivityAction: string
{
    case Created = 'created';
    case Edited = 'edited';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case Archived = 'archived';
    case OwnershipTransferred = 'ownership_transferred';
    case Confirmed = 'confirmed';
    case Skipped = 'skipped';
    case Joined = 'joined';
    case Left = 'left';
    case Removed = 'removed';
    case RoleChanged = 'role_changed';
    case RoleLimitedByPlan = 'role_limited_by_plan';
    case Invited = 'invited';
    case InvitationRevoked = 'invitation_revoked';
}

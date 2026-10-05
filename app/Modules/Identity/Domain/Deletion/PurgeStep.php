<?php

namespace App\Modules\Identity\Domain\Deletion;

/**
 * The ordered, idempotent steps of an account purge (spec 022, R-12). The
 * order is the contract: the name is erased before any membership is
 * tombstoned, invitations are revoked before the data key is destroyed, the
 * completion mail is sent before the address goes.
 */
enum PurgeStep: string
{
    case Begin = 'begin';
    case EraseName = 'erase_name';
    case RevokeInvitations = 'revoke_invitations';
    case ResolveOwnedWallets = 'resolve_owned_wallets';
    case LeaveWallets = 'leave_wallets';
    case Budgets = 'budgets';
    case Categories = 'categories';
    case Subscriptions = 'subscriptions';
    case Exports = 'exports';
    case CompletionMail = 'completion_mail';
    case EraseIdentity = 'erase_identity';

    /**
     * The step's place in the order, from 0.
     */
    public function position(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    public function isBefore(self $other): bool
    {
        return $this->position() < $other->position();
    }
}

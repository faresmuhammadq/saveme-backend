<?php

namespace App\Modules\Sharing\Application;

use App\Modules\Sharing\Domain\InvitationStatus;
use App\Modules\Sharing\Domain\Ports\InvitationRepository;
use App\Modules\Wallets\Application\Deletion\WalletDeletionParticipant;
use Closure;
use DateTimeImmutable;

/**
 * A deleted wallet's pending invitations go with it, in the same unit of work
 * (spec 016, FR-023; spec 010 FR-010): each is closed as `revoked`, a
 * tombstone with its address erased and its own change_seq from the wallet's
 * counter. No activity entry and no event: the wallet is being deleted.
 */
final class InvitationsGoWithWallet implements WalletDeletionParticipant
{
    public function __construct(private readonly InvitationRepository $invitations) {}

    public function tombstoneWithin(string $walletId, DateTimeImmutable $at, Closure $nextChangeSeq): void
    {
        foreach ($this->invitations->pendingIn($walletId) as $invitation) {
            $this->invitations->close($invitation->id, InvitationStatus::Revoked, $at, $nextChangeSeq(), null);
        }
    }
}

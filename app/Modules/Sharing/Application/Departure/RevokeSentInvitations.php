<?php

namespace App\Modules\Sharing\Application\Departure;

use App\Modules\Identity\Application\Deletion\AccountPurgeParticipant;
use App\Modules\Identity\Application\Deletion\PurgeContext;
use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;
use App\Modules\Sharing\Application\InvitationService;
use App\Modules\Sharing\Domain\Ports\InvitationRepository;

/**
 * Sharing's step of an account purge (spec 022, FR-043): every pending
 * invitation the departing person sent is revoked, its address erased,
 * before their data key is destroyed. Runs while their role is still valid;
 * a no-op once none is pending.
 */
final class RevokeSentInvitations implements AccountPurgeParticipant
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly InvitationService $service,
    ) {}

    public function step(): PurgeStep
    {
        return PurgeStep::RevokeInvitations;
    }

    public function purge(string $userId, PurgeContext $context): PurgeCounts
    {
        $revoked = 0;

        foreach ($this->invitations->pendingInvitedBy($userId) as $invitation) {
            $revoked += $this->service->revokeForDepartingInviter($userId, $invitation->walletId, $invitation->id) ? 1 : 0;
        }

        return PurgeCounts::of('invitations_revoked', $revoked);
    }
}

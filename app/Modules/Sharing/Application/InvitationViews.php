<?php

namespace App\Modules\Sharing\Application;

use App\Modules\Identity\Application\Sharing\InvitationAddresses;
use App\Modules\Sharing\Application\Dto\InvitationView;
use App\Modules\Sharing\Domain\InvitationRecord;

/**
 * Builds what a read presents: opens the sealed address of a pending email
 * invitation under its inviter's key, and nothing else (spec 016, R-5).
 */
final class InvitationViews
{
    public function __construct(private readonly InvitationAddresses $addresses) {}

    public function of(InvitationRecord $invitation): InvitationView
    {
        $email = $invitation->addressSealed === null || $invitation->isTombstone()
            ? null
            : $this->addresses->open($invitation->invitedBy, $invitation->addressSealed);

        return new InvitationView($invitation, $email);
    }
}

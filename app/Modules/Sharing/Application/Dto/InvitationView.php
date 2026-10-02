<?php

namespace App\Modules\Sharing\Application\Dto;

use App\Modules\Sharing\Domain\InvitationRecord;

/**
 * An invitation as a read presents it: the record and the opened address,
 * which is present only for a pending email invitation (spec 016, R-5).
 */
final readonly class InvitationView
{
    public function __construct(
        public InvitationRecord $invitation,
        public ?string $inviteeEmail,
    ) {}
}

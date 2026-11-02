<?php

namespace App\Modules\Sharing\Application\Dto;

use App\Modules\Sharing\Domain\SharedBudgets\SharedBudgetInvitationRecord;

/**
 * A saved Shared Budget invitation, whether this write created it, and the
 * shareable link, present only on the create that made it (the token exists
 * nowhere else).
 */
final readonly class SavedSharedBudgetInvitation
{
    public function __construct(
        public SharedBudgetInvitationRecord $invitation,
        public bool $created,
        public ?string $shareUrl = null,
    ) {}
}

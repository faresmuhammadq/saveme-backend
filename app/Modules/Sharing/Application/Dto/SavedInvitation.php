<?php

namespace App\Modules\Sharing\Application\Dto;

/**
 * The outcome of a create: the invitation, whether this call created it, and
 * the one-time link of a link invitation. The link exists only here.
 */
final readonly class SavedInvitation
{
    public function __construct(
        public InvitationView $invitation,
        public bool $created,
        public ?string $shareUrl = null,
    ) {}
}

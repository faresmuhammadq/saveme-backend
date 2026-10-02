<?php

namespace App\Modules\Sharing\Application\Dto;

use App\Modules\Sharing\Domain\InvitedRole;
use DateTimeImmutable;

/**
 * What a token offers, for the invitee's confirmation screen: no amount, no
 * balance, no address (spec 016, A-5).
 */
final readonly class InvitationPreview
{
    public function __construct(
        public string $invitationId,
        public string $walletId,
        public string $walletName,
        public InvitedRole $role,
        public ?string $invitedByDisplayName,
        public DateTimeImmutable $expiresAt,
        public bool $alreadyMember,
    ) {}
}

<?php

namespace App\Modules\Sharing\Application\Dto;

use DateTimeImmutable;

/**
 * What a token offers, for the invitee's confirmation screen (ADR-0027): the
 * budget they would join and who owns it. No amount, wallet or address.
 * Joining shows them the amount, date, category, note and author of every
 * entry, and never anyone's wallet.
 */
final readonly class SharedBudgetInvitationPreview
{
    public function __construct(
        public string $invitationId,
        public string $sharedBudgetId,
        public string $name,
        public string $currency,
        public ?string $ownerDisplayName,
        public int $memberCount,
        public DateTimeImmutable $expiresAt,
        public bool $alreadyMember,
    ) {}
}

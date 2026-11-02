<?php

namespace App\Modules\Sharing\Domain\SharedBudgets;

use App\Modules\Sharing\Domain\InvitationStatus;
use DateTimeImmutable;

/**
 * A Shared Budget invitation as the application knows it. The token hash and
 * the responder are internal and never go on the wire.
 */
final readonly class SharedBudgetInvitationRecord
{
    public function __construct(
        public string $id,
        public string $sharedBudgetId,
        public string $invitedBy,
        public string $tokenHash,
        public InvitationStatus $status,
        public DateTimeImmutable $expiresAt,
        public ?string $responderUserId,
        public int $version,
        public int $changeSeq,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    public function isTombstone(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * Pending and not past its expiry instant: the only state a token works
     * in, whether or not the hourly sweep has closed it yet.
     */
    public function isLiveAt(DateTimeImmutable $now): bool
    {
        return $this->status === InvitationStatus::Pending && $this->expiresAt > $now;
    }
}

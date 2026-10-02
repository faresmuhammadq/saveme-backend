<?php

namespace App\Modules\Sharing\Domain;

use DateTimeImmutable;

/**
 * An invitation as the application layer knows it. The token hash, the
 * address lookup value and the responder are internal and never go on the
 * wire; the sealed address is opened by Identity's InvitationAddresses only.
 */
final readonly class InvitationRecord
{
    public function __construct(
        public string $id,
        public string $walletId,
        public string $invitedBy,
        public InvitedRole $role,
        public InvitationChannel $channel,
        public ?string $addressSealed,
        public ?string $addressLookup,
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
     * Live (pending) and not past its expiry instant: the only state a token
     * works in, whether or not the hourly sweep has closed it yet.
     */
    public function isLiveAt(DateTimeImmutable $now): bool
    {
        return $this->status === InvitationStatus::Pending && $this->expiresAt > $now;
    }
}

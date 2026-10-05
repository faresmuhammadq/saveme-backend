<?php

namespace App\Modules\Identity\Domain\Account;

use DateTimeImmutable;

/**
 * What the application layer knows about a User, without the persistence
 * model: identity, credential hash, lifecycle and verification.
 */
final readonly class UserRecord
{
    public function __construct(
        public string $id,
        public string $passwordHash,
        public AccountState $state,
        public ?DateTimeImmutable $emailVerifiedAt,
        public ?DateTimeImmutable $deletionScheduledFor,
        public ?DateTimeImmutable $deletionRequestedAt = null,
    ) {}

    public function isVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }
}

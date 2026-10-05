<?php

namespace App\Modules\Identity\Domain\Ports;

use App\Modules\Identity\Domain\Account\UserRecord;
use DateTimeImmutable;

interface UserRepository
{
    public function create(string $id, string $sealedEmail, string $lookup, string $passwordHash): void;

    public function find(string $id): ?UserRecord;

    public function findByLookup(string $lookup): ?UserRecord;

    /**
     * When the account was created.
     */
    public function createdAt(string $id): ?DateTimeImmutable;

    public function sealedEmail(string $id): string;

    public function lookupOf(string $id): string;

    public function updatePasswordHash(string $id, string $passwordHash): void;

    /**
     * @return bool whether the address was unverified until now
     */
    public function markVerified(string $id): bool;

    public function scheduleDeletion(string $id, DateTimeImmutable $requestedAt, DateTimeImmutable $scheduledFor): void;

    /**
     * Returns the account to active, only if a deletion was pending.
     *
     * @return bool whether a pending deletion was cancelled by this call
     */
    public function cancelDeletion(string $id): bool;

    /**
     * Population counts of accounts that still exist (not purged), at $now.
     *
     * @return array{total: int, verified: int, pending_deletion: int, new_7_days: int, new_30_days: int}
     */
    public function figures(DateTimeImmutable $now): array;
}

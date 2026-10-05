<?php

namespace App\Modules\Identity\Domain\Ports;

use App\Modules\Identity\Domain\Account\AccountState;
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
     * pending_deletion → purging, only if the account is pending and its
     * scheduled instant has passed.
     *
     * @return bool whether this call moved it
     */
    public function markPurging(string $id, DateTimeImmutable $now): bool;

    /**
     * Reduces the account to a husk: purged, no address, lookup value or
     * password, the deletion dates cleared.
     */
    public function toHusk(string $id, DateTimeImmutable $now): void;

    /**
     * Accounts whose purge is due or under way: pending and past their
     * scheduled instant, and every `purging` one, soonest first.
     *
     * @return list<string>
     */
    public function purgeCandidates(DateTimeImmutable $now, int $limit): array;

    /**
     * Pending accounts whose purge is within $days and which have not been reminded.
     *
     * @return list<string>
     */
    public function dueReminders(DateTimeImmutable $now, int $days, int $limit): array;

    /**
     * Stamps the reminder, only if the account is pending and not yet reminded.
     *
     * @return bool whether this call stamped it
     */
    public function markReminded(string $id, DateTimeImmutable $now): bool;

    /**
     * Deletes husks purged before the cut.
     */
    public function deleteHusksBefore(DateTimeImmutable $cut): int;

    /**
     * The lifecycle state of each given account; an unknown id is absent.
     *
     * @param  list<string>  $ids
     * @return array<string, AccountState>
     */
    public function statesOf(array $ids): array;

    /**
     * Population counts of accounts that still exist (not purging or purged), at $now.
     *
     * @return array{total: int, verified: int, pending_deletion: int, new_7_days: int, new_30_days: int}
     */
    public function figures(DateTimeImmutable $now): array;
}

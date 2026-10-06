<?php

namespace App\Modules\Notifications\Domain\Ports;

use App\Modules\Notifications\Domain\Registration\PushRegistrationRecord;
use DateTimeImmutable;

interface RegistrationRepository
{
    /**
     * Stores the session's token in one unit of work: any other session
     * holding the same token loses its registration, then this session's row
     * is inserted or replaced (spec 023, FR-003, FR-004).
     */
    public function upsertForSession(string $sessionId, string $userId, string $tokenSealed, string $tokenLookup, DateTimeImmutable $now): PushRegistrationRecord;

    /**
     * @return bool whether a registration was removed
     */
    public function deleteForSession(string $sessionId): bool;

    public function deleteById(string $registrationId): void;

    /**
     * Every registration of the person.
     *
     * @return list<PushRegistrationRecord>
     */
    public function liveFor(string $userId): array;

    /**
     * Removes registrations not refreshed since the cut.
     *
     * @return int how many
     */
    public function deleteIdleBefore(DateTimeImmutable $cut): int;

    public function deleteAllFor(string $userId): int;

    public function countFor(string $userId): int;
}

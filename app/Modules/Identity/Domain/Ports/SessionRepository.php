<?php

namespace App\Modules\Identity\Domain\Ports;

use App\Modules\Identity\Domain\Sessions\EndReason;
use App\Modules\Identity\Domain\Sessions\SessionRecord;
use App\Modules\Identity\Domain\Sessions\SessionState;
use DateTimeImmutable;

interface SessionRepository
{
    public function find(string $sessionId): ?SessionRecord;

    public function activeForDevice(string $userId, string $deviceId): ?SessionRecord;

    /**
     * Ends an active session. A session already ended is left as it is.
     *
     * @return bool whether this call ended it
     */
    public function end(string $sessionId, EndReason $reason): bool;

    /**
     * @return list<string>
     */
    public function activeIdsFor(string $userId, ?string $except = null): array;

    /**
     * Which of the given sessions are the person's and still active.
     *
     * @param  list<string>  $sessionIds
     * @return list<string>
     */
    public function activeAmong(string $userId, array $sessionIds): array;

    /**
     * How many sessions are active and not past their own expiry at $now.
     */
    public function activeCountFor(string $userId, DateTimeImmutable $now): int;

    /**
     * One page of a user's sessions, keyset-paged on (sort column, id).
     *
     * @param  'signed_in_at'|'last_used_at'  $sort
     * @param  array{value: string|null, id: string}|null  $after
     * @return list<SessionRecord>
     */
    public function page(string $userId, ?SessionState $state, string $sort, bool $descending, ?array $after, int $limit): array;

    /**
     * Marks every active session unused since $idleSince as expired.
     *
     * @return list<array{id: string, user_id: string}>
     */
    public function expireIdleSince(DateTimeImmutable $idleSince): array;
}

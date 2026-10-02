<?php

namespace App\Modules\Identity\Domain\Ports;

use App\Modules\Identity\Domain\Sessions\SessionRecord;

/**
 * The user scope row as the change feed and the purge see it (spec 013,
 * R-5, R-10): the counter, the purge horizon, and the sessions above a
 * position.
 */
interface UserScope
{
    /**
     * @return array{counter: int, purged: int}|null null when the user row is gone
     */
    public function scopeOf(string $userId): ?array;

    /**
     * The person's sessions with `change_seq` above $after, in position order.
     *
     * @return list<SessionRecord>
     */
    public function sessionsChangedAfter(string $userId, int $after, int $limit): array;

    /**
     * Locks the user row and raises the purge horizon to $position if that is
     * higher; never lowers it.
     */
    public function raiseHorizon(string $userId, int $position): void;
}

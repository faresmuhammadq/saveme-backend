<?php

namespace App\Modules\Identity\Application\Sessions;

use App\Modules\Identity\Domain\Ports\SessionRepository;

/**
 * What other modules may ask about device sessions (spec 023, R-5): which of
 * a person's sessions are still active, and whether an id is the caller's own
 * current one. Identifiers and booleans only; nothing of the session leaves.
 */
final class SessionStatus
{
    public function __construct(private readonly SessionRepository $sessions) {}

    /**
     * The ids, among the given ones, of the person's sessions that are active.
     *
     * @param  list<string>  $sessionIds
     * @return list<string>
     */
    public function activeAmong(string $userId, array $sessionIds): array
    {
        return $this->sessions->activeAmong($userId, $sessionIds);
    }

    /**
     * Whether $sessionId is the session the caller is using, and it is theirs
     * and active.
     */
    public function isCurrent(string $userId, string $sessionId, string $currentSessionId): bool
    {
        return $sessionId === $currentSessionId && $this->activeAmong($userId, [$sessionId]) !== [];
    }
}

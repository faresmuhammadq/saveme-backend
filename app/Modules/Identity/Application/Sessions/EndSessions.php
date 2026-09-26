<?php

namespace App\Modules\Identity\Application\Sessions;

use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\SessionRevokedDetail;
use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Events\DeviceSessionEnded;
use App\Modules\Identity\Domain\Ports\SessionRepository;
use App\Modules\Identity\Domain\Refusals\SessionNotFound;
use App\Modules\Identity\Domain\Sessions\EndReason;
use Illuminate\Support\Facades\DB;

/**
 * Ending sessions (security baseline, Device sessions): effective on the next
 * request, leaving every other session working, and the row kept with its
 * end time and reason. Each ended session is audited once and announced
 * after commit, so Notifications can drop its push registration.
 */
final class EndSessions
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Ends one of the caller's sessions. Already ended: nothing changes.
     */
    public function endOne(string $callerId, string $currentSessionId, string $sessionId): void
    {
        $session = $this->sessions->find($sessionId);

        if ($session === null || $session->userId !== $callerId) {
            throw new SessionNotFound;
        }

        $this->end($callerId, [$sessionId], $sessionId === $currentSessionId ? EndReason::SignedOut : EndReason::EndedByOwner);
    }

    public function endOthers(string $callerId, string $currentSessionId): void
    {
        $this->end($callerId, $this->sessions->activeIdsFor($callerId, except: $currentSessionId), EndReason::EndedByOwner);
    }

    public function signOut(string $callerId, string $currentSessionId): void
    {
        $this->end($callerId, [$currentSessionId], EndReason::SignedOut);
    }

    /**
     * Ends every active session of a person, or all but one. Used by password
     * change and reset; runs inside the caller's transaction when there is one.
     *
     * @return list<string> the sessions this call ended
     */
    public function endAllFor(string $userId, EndReason $reason, ?string $except = null, ActorType $actor = ActorType::User): array
    {
        return $this->end($userId, $this->sessions->activeIdsFor($userId, except: $except), $reason, $actor);
    }

    /**
     * @param  list<string>  $sessionIds
     * @return list<string>
     */
    private function end(string $userId, array $sessionIds, EndReason $reason, ActorType $actor = ActorType::User): array
    {
        return DB::transaction(function () use ($userId, $sessionIds, $reason, $actor): array {
            $ended = [];

            foreach ($sessionIds as $sessionId) {
                if (! $this->sessions->end($sessionId, $reason)) {
                    continue;
                }

                $ended[] = $sessionId;
                $this->audit->record(new AuditRecord(
                    AuditEvent::SessionRevoked,
                    $actor,
                    $actor === ActorType::System ? null : $userId,
                    SubjectType::Session,
                    $sessionId,
                    new SessionRevokedDetail($reason),
                ));

                event(new DeviceSessionEnded(...Events::stamp(), userId: $userId, sessionId: $sessionId, reason: $reason->value));
            }

            return $ended;
        });
    }
}

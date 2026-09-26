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
use App\Modules\Identity\Domain\Sessions\EndReason;

/**
 * Records sessions idle past the inactivity window as expired, so the owner's
 * list and the sync feed say so (spec 007, R-3). The guard already refuses
 * them whether or not this has run.
 */
final class ExpireIdleSessions
{
    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly AuditLog $audit,
    ) {}

    public function handle(): int
    {
        $expired = $this->sessions->expireIdleSince(now()->subDays((int) config('identity.session_inactivity_days'))->toDateTimeImmutable());

        foreach ($expired as $session) {
            $this->audit->record(new AuditRecord(AuditEvent::SessionRevoked, ActorType::System, null, SubjectType::Session, $session['id'], new SessionRevokedDetail(EndReason::Expired)));
            event(new DeviceSessionEnded(...Events::stamp(), userId: $session['user_id'], sessionId: $session['id'], reason: EndReason::Expired->value));
        }

        return count($expired);
    }
}

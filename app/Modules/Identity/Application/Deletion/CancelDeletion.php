<?php

namespace App\Modules\Identity\Application\Deletion;

use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\DeletionCancelledDetail;
use App\Modules\Identity\Application\Events;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Events\UserDeletionCancelled;
use App\Modules\Identity\Domain\Ports\SuccessorChoiceRepository;
use App\Modules\Identity\Domain\Ports\UserRepository;
use Illuminate\Support\Facades\DB;

/**
 * Returns an account in its grace period to active, losing nothing (security
 * baseline, Deletion rule 1). Explicitly, or because someone signed in anew
 * (spec 007, FR-054). Idempotent: with nothing pending, nothing happens.
 */
final class CancelDeletion
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLog $audit,
        private readonly SuccessorChoiceRepository $choices,
    ) {}

    /**
     * @param  'explicit'|'sign_in'  $reason
     * @return bool whether a pending deletion was cancelled
     */
    public function cancel(string $userId, string $reason, ?string $sessionId = null): bool
    {
        return DB::transaction(function () use ($userId, $reason, $sessionId): bool {
            if (! $this->users->cancelDeletion($userId)) {
                return false;
            }

            $this->choices->deleteFor($userId);

            $this->audit->record(AuditRecord::byUser(AuditEvent::DeletionCancelled, $userId, SubjectType::User, $userId, new DeletionCancelledDetail($reason)));

            event(new UserDeletionCancelled(...Events::stamp(), userId: $userId, reason: $reason, sessionId: $sessionId));

            return true;
        });
    }
}

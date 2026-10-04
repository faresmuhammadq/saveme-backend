<?php

namespace App\Modules\Admin\Application\Audit;

use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\AuditDetail;
use App\Modules\Identity\Application\Audit\Details\LockoutDetail;
use App\Modules\Identity\Application\Audit\Details\NoDetail;
use App\Modules\Identity\Application\Audit\Details\SessionRevokedDetail;
use App\Modules\Identity\Application\Audit\Details\SignInFailedDetail;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Sessions\EndReason;

/**
 * Admin's façade over Identity's one audit log (security baseline, Who owns
 * the audit log; spec 018, R-13, contracts/commands-and-audit.md). It writes
 * through Identity's service and keeps no second store. Call it inside the
 * transaction of the act it records.
 */
final class AdminAuditService
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  'code'|'recovery_code'  $factor
     */
    public function signInSucceeded(string $operatorId, string $factor, int $recoveryCodesLeft): void
    {
        $this->operatorActs(AuditEvent::SignInSucceeded, $operatorId, new OperatorSignInDetail($factor, $recoveryCodesLeft));
    }

    /**
     * @param  string  $stage  password, second_factor, enrolment or reconfirm
     * @param  string|null  $unknownLookup  the lookup value when the email is not an operator's
     */
    public function signInFailed(?string $operatorId, string $stage, ?string $unknownLookup = null): void
    {
        $this->audit->record(new AuditRecord(
            AuditEvent::SignInFailed,
            ActorType::System,
            null,
            SubjectType::Operator,
            $operatorId,
            $unknownLookup === null ? new SignInFailedDetail(stage: $stage) : SignInFailedDetail::forUnknownAddress($unknownLookup, $stage),
        ));
    }

    public function lockoutApplied(?string $operatorId, int $minutes, ?string $unknownLookup = null): void
    {
        $this->audit->record(new AuditRecord(
            AuditEvent::LockoutApplied,
            ActorType::System,
            null,
            SubjectType::Operator,
            $operatorId,
            new LockoutDetail($minutes, $unknownLookup === null ? null : substr($unknownLookup, 0, 8)),
        ));
    }

    public function signedOut(string $operatorId): void
    {
        $this->operatorActs(AuditEvent::SessionRevoked, $operatorId, new SessionRevokedDetail(EndReason::SignedOut));
    }

    public function passwordChanged(string $operatorId): void
    {
        $this->operatorActs(AuditEvent::PasswordChanged, $operatorId, new NoDetail);
    }

    /**
     * An act an operator did, on an account (their own, or the requester's).
     */
    public function adminActionByOperator(string $actorId, string $subjectId, AdminAction $action, ?string $reenrolmentRequestId = null, ?AuditReason $reason = null): void
    {
        $this->audit->record(new AuditRecord(
            AuditEvent::AdminAction,
            ActorType::Operator,
            $actorId,
            SubjectType::Operator,
            $subjectId,
            new AdminActionDetail($action, $reenrolmentRequestId, $reason),
        ));
    }

    /**
     * An act the command line or the scheduler did.
     */
    public function adminActionBySystem(string $subjectId, AdminAction $action, ?string $reenrolmentRequestId = null): void
    {
        $this->audit->record(new AuditRecord(
            AuditEvent::AdminAction,
            ActorType::System,
            null,
            SubjectType::Operator,
            $subjectId,
            new AdminActionDetail($action, $reenrolmentRequestId),
        ));
    }

    private function operatorActs(AuditEvent $event, string $operatorId, AuditDetail $detail): void
    {
        $this->audit->record(new AuditRecord($event, ActorType::Operator, $operatorId, SubjectType::Operator, $operatorId, $detail));
    }
}

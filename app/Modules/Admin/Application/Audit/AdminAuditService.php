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

    /**
     * An operator read a payment claim's address or opened its receipt (spec 020,
     * FR-014a, FR-045): one entry per read, about the claim.
     */
    public function claimReadByOperator(string $operatorId, string $claimId, AdminAction $action): void
    {
        $this->audit->record(new AuditRecord(
            AuditEvent::AdminAction,
            ActorType::Operator,
            $operatorId,
            SubjectType::PaymentClaim,
            $claimId,
            new AdminActionDetail($action),
        ));
    }

    /**
     * An operator opened an account page (spec 021, FR-024): one entry per opening.
     */
    public function accountViewed(string $operatorId, string $userId): void
    {
        $this->audit->record(new AuditRecord(
            AuditEvent::AdminAction,
            ActorType::Operator,
            $operatorId,
            SubjectType::User,
            $userId,
            new AdminActionDetail(AdminAction::AccountViewed),
        ));
    }

    /**
     * A lookup found nothing (spec 021, FR-025). The subject is the operator
     * themselves; the term typed is never recorded, only how it was typed.
     *
     * @param  'address'|'id'  $by
     */
    public function accountLookupMissed(string $operatorId, string $by): void
    {
        $this->audit->record(new AuditRecord(
            AuditEvent::AdminAction,
            ActorType::Operator,
            $operatorId,
            SubjectType::Operator,
            $operatorId,
            new AdminActionDetail(AdminAction::AccountLookupMissed, by: $by),
        ));
    }

    /**
     * A plan was saved or retired (spec 021, FR-038). Field names and the
     * reason only, never a price or a capability value.
     *
     * @param  list<string>  $fields
     */
    public function planChanged(string $operatorId, string $planId, AdminAction $action, array $fields, string $reason): void
    {
        sort($fields);

        $this->audit->record(new AuditRecord(
            AuditEvent::AdminAction,
            ActorType::Operator,
            $operatorId,
            SubjectType::Plan,
            $planId,
            new AdminActionDetail(
                $action,
                reason: new AuditReason($reason),
                fields: $action === AdminAction::PlanUpdated ? implode(',', $fields) : null,
            ),
        ));
    }

    /**
     * An operator read the audit log (spec 021, FR-043): the filters and the
     * page, subject the reading operator.
     *
     * @param  array<string, string>  $detail
     */
    public function auditRead(string $operatorId, array $detail): void
    {
        $this->audit->record(new AuditRecord(
            AuditEvent::AuditRead,
            ActorType::Operator,
            $operatorId,
            SubjectType::Operator,
            $operatorId,
            new AuditReadDetail($detail),
        ));
    }

    private function operatorActs(AuditEvent $event, string $operatorId, AuditDetail $detail): void
    {
        $this->audit->record(new AuditRecord($event, ActorType::Operator, $operatorId, SubjectType::Operator, $operatorId, $detail));
    }
}

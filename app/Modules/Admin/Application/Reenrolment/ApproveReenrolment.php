<?php

namespace App\Modules\Admin\Application\Reenrolment;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Domain\AuditReason;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Operators\ReenrolmentState;
use App\Modules\Admin\Domain\Operators\Refusals\ReenrolmentRefused;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use App\Modules\Admin\Domain\Ports\RecoveryCodeStore;
use App\Modules\Admin\Domain\Ports\ReenrolmentStore;
use Illuminate\Support\Facades\DB;

/**
 * An operator approves another's re-enrolment (FR-031 to FR-033). The request
 * row is locked, so two approvals arriving together are recorded once each
 * and the request completes exactly once. The second distinct approval
 * completes it in the same transaction: the requester's secret and every
 * recovery code stop working, their sessions end (the epoch moves) and the
 * account awaits enrolment.
 */
final class ApproveReenrolment
{
    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly ReenrolmentStore $requests,
        private readonly RecoveryCodeStore $recoveryCodes,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @return bool whether this approval completed the request
     *
     * @throws ReenrolmentRefused
     */
    public function handle(string $approverId, string $requestId, AuditReason $reason): bool
    {
        return DB::transaction(function () use ($approverId, $requestId, $reason): bool {
            $request = $this->requests->lockForUpdate($requestId);

            if ($request === null || $request->state !== ReenrolmentState::Open) {
                throw new ReenrolmentRefused('closed');
            }

            $now = now()->toDateTimeImmutable();

            if ($request->expiresAt <= $now) {
                throw new ReenrolmentRefused('expired', $request->expiresAt);
            }

            if ($request->operatorId === $approverId) {
                throw new ReenrolmentRefused('own_request');
            }

            $approver = $this->operators->find($approverId);

            if ($approver === null || $approver->state !== OperatorState::Active) {
                throw new ReenrolmentRefused('closed');
            }

            if ($this->requests->hasApproval($requestId, $approverId)) {
                throw new ReenrolmentRefused('already_approved');
            }

            $this->requests->addApproval($requestId, $approverId, $now);
            $this->audit->adminActionByOperator($approverId, $request->operatorId, AdminAction::ReenrolmentApproved, $requestId, $reason);

            if ($this->requests->approvalCount($requestId) < 2) {
                return false;
            }

            $this->requests->close($requestId, ReenrolmentState::Completed, $approverId, $now);
            $this->operators->lockForUpdate($request->operatorId);
            $this->operators->clearFactor($request->operatorId);
            $this->recoveryCodes->deleteAll($request->operatorId);
            $this->audit->adminActionBySystem($request->operatorId, AdminAction::ReenrolmentCompleted, $requestId);

            return true;
        });
    }
}

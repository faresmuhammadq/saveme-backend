<?php

namespace App\Modules\Admin\Application\Enrolment;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Application\Dto\IssuedRecoveryCodes;
use App\Modules\Admin\Application\SignIn\FailureRecorder;
use App\Modules\Admin\Domain\Operators\AdminAction;
use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Operators\Refusals\PendingSignInExpired;
use App\Modules\Admin\Domain\Operators\Refusals\SecondFactorInvalid;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use App\Modules\Admin\Domain\SecondFactor\Totp;
use Illuminate\Support\Facades\DB;

/**
 * Completes enrolment once the person enters a correct code from the key
 * they were shown (FR-021): the pending secret becomes the secret, the
 * confirming step is recorded so it cannot be replayed at sign-in, the
 * account becomes active, and ten recovery codes are issued.
 */
final class ConfirmEnrolment
{
    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly IssueRecoveryCodes $issue,
        private readonly FailureRecorder $failures,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @throws SecondFactorInvalid|OperatorLocked|PendingSignInExpired
     */
    public function handle(string $operatorId, string $code): IssuedRecoveryCodes
    {
        $operator = $this->operators->find($operatorId);

        if ($operator === null || $operator->state !== OperatorState::AwaitingEnrolment) {
            throw new PendingSignInExpired;
        }

        $this->failures->assertOpen($operator->emailLookup);

        $issued = DB::transaction(function () use ($operatorId, $code): ?IssuedRecoveryCodes {
            $locked = $this->operators->lockForUpdate($operatorId);

            if ($locked === null || $locked->state !== OperatorState::AwaitingEnrolment || $locked->pendingSecret === null) {
                throw new PendingSignInExpired;
            }

            $step = Totp::verify($locked->pendingSecret, $code, now()->getTimestamp(), null, (int) config('admin.auth.drift_steps'));

            if ($step === null) {
                return null;
            }

            $this->operators->enrol($operatorId, $locked->pendingSecret, $step, now()->toDateTimeImmutable());
            $issued = $this->issue->handle($operatorId);
            $this->audit->adminActionByOperator($operatorId, $operatorId, AdminAction::EnrolmentCompleted);

            return $issued;
        });

        if ($issued === null) {
            $this->failures->record($operator->emailLookup, $operator->id, 'enrolment');

            throw new SecondFactorInvalid;
        }

        return $issued;
    }
}

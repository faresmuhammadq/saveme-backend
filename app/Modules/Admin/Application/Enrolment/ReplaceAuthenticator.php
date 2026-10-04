<?php

namespace App\Modules\Admin\Application\Enrolment;

use App\Modules\Admin\Application\Audit\AdminAuditService;
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
 * Links a new authenticator from the security page (FR-025). The current one
 * keeps working until a code from the new one is confirmed; recovery codes
 * stay as they were. Sessions are kept: nothing here is a credential reset.
 */
final class ReplaceAuthenticator
{
    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly FailureRecorder $failures,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @throws SecondFactorInvalid|OperatorLocked|PendingSignInExpired
     */
    public function handle(string $operatorId, string $code): void
    {
        $operator = $this->operators->find($operatorId);

        if ($operator === null || $operator->state !== OperatorState::Active) {
            throw new PendingSignInExpired;
        }

        $this->failures->assertOpen($operator->emailLookup);

        $replaced = DB::transaction(function () use ($operatorId, $code): bool {
            $locked = $this->operators->lockForUpdate($operatorId);

            if ($locked === null || $locked->state !== OperatorState::Active || $locked->pendingSecret === null) {
                throw new PendingSignInExpired;
            }

            $step = Totp::verify($locked->pendingSecret, $code, now()->getTimestamp(), null, (int) config('admin.auth.drift_steps'));

            if ($step === null) {
                return false;
            }

            $this->operators->replaceSecret($operatorId, $locked->pendingSecret, $step, now()->toDateTimeImmutable());
            $this->audit->adminActionByOperator($operatorId, $operatorId, AdminAction::AuthenticatorReplaced);

            return true;
        });

        if (! $replaced) {
            $this->failures->record($operator->emailLookup, $operator->id, 'enrolment');

            throw new SecondFactorInvalid;
        }
    }
}

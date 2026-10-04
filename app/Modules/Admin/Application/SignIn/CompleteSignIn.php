<?php

namespace App\Modules\Admin\Application\SignIn;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Application\Dto\VerifiedFactor;
use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Operators\Refusals\PendingSignInExpired;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use Illuminate\Support\Facades\DB;

/**
 * The last step of every sign-in: the operator is active, nothing changed
 * since the password was accepted, and the second factor was proven (spec
 * 018, R-6). Clears the failure counter, notes the sign-in and writes the one
 * entry.
 */
final class CompleteSignIn
{
    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly FailureRecorder $failures,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @return int the credential epoch the session must carry
     *
     * @throws PendingSignInExpired when the account changed under the half-finished sign-in
     */
    public function handle(string $operatorId, int $pendingEpoch, VerifiedFactor $factor): int
    {
        return DB::transaction(function () use ($operatorId, $pendingEpoch, $factor): int {
            $operator = $this->operators->lockForUpdate($operatorId);

            if ($operator === null || $operator->state !== OperatorState::Active || $operator->credentialEpoch !== $pendingEpoch) {
                throw new PendingSignInExpired;
            }

            $this->failures->clear($operator->emailLookup);
            $this->operators->markSignedIn($operatorId, now()->toDateTimeImmutable());
            $this->audit->signInSucceeded($operatorId, $factor->kind, $factor->codesLeft);

            return $operator->credentialEpoch;
        });
    }
}

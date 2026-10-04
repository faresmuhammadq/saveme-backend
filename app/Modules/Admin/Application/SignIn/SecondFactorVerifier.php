<?php

namespace App\Modules\Admin\Application\SignIn;

use App\Modules\Admin\Application\Dto\VerifiedFactor;
use App\Modules\Admin\Domain\Operators\FactorPurpose;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Operators\Refusals\SecondFactorInvalid;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use App\Modules\Admin\Domain\Ports\RecoveryCodeHasher;
use App\Modules\Admin\Domain\Ports\RecoveryCodeStore;
use App\Modules\Admin\Domain\SecondFactor\RecoveryCode;
use App\Modules\Admin\Domain\SecondFactor\Totp;
use Illuminate\Support\Facades\DB;

/**
 * Checks a one-time code, or failing that a recovery code, for an operator
 * (spec 018, contracts/second-factor.md, Verifier service). The operator row
 * is locked while the code is judged and its step recorded, so a code is
 * used once even across two simultaneous submissions (R-3). A failure is
 * counted after the transaction, so it survives.
 */
final class SecondFactorVerifier
{
    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly RecoveryCodeStore $recoveryCodes,
        private readonly RecoveryCodeHasher $hasher,
        private readonly FailureRecorder $failures,
    ) {}

    /**
     * @throws SecondFactorInvalid|OperatorLocked
     */
    public function verify(string $operatorId, string $input, FactorPurpose $purpose): VerifiedFactor
    {
        $operator = $this->operators->find($operatorId);

        if ($operator === null) {
            throw new SecondFactorInvalid;
        }

        $this->failures->assertOpen($operator->emailLookup);

        $verified = DB::transaction(fn (): ?VerifiedFactor => $this->judge($operatorId, $input));

        if ($verified === null) {
            $this->failures->record($operator->emailLookup, $operator->id, $purpose->failureStage());

            throw new SecondFactorInvalid;
        }

        return $verified;
    }

    private function judge(string $operatorId, string $input): ?VerifiedFactor
    {
        $operator = $this->operators->lockForUpdate($operatorId);

        if ($operator === null || ! $operator->canSignIn() || $operator->secret === null) {
            return null;
        }

        $step = Totp::verify(
            $operator->secret,
            $input,
            now()->getTimestamp(),
            $operator->lastCodeStep,
            (int) config('admin.auth.drift_steps'),
        );

        if ($step !== null) {
            $this->operators->recordCodeStep($operatorId, $step);

            return new VerifiedFactor('code', $this->recoveryCodes->unusedCount($operatorId));
        }

        $normalised = RecoveryCode::normalise($input);

        if ($normalised !== null && $this->recoveryCodes->consumeMatching($operatorId, $this->hasher->hash($normalised))) {
            return new VerifiedFactor('recovery_code', $this->recoveryCodes->unusedCount($operatorId));
        }

        return null;
    }
}

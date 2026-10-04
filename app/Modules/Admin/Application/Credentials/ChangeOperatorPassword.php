<?php

namespace App\Modules\Admin\Application\Credentials;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Application\Dto\PasswordChangeResult;
use App\Modules\Admin\Application\SignIn\FailureRecorder;
use App\Modules\Admin\Domain\Operators\OperatorPasswordPolicy;
use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Operators\Refusals\InvalidOperatorCredentials;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Operators\Refusals\PasswordRuleBroken;
use App\Modules\Admin\Domain\Operators\Refusals\PendingSignInExpired;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * An operator chooses their own password: forced at first sign-in, or from
 * the security page (FR-004, FR-062). The epoch moves, so every other session
 * ends; the caller keeps its own by adopting the returned epoch.
 */
final class ChangeOperatorPassword
{
    public function __construct(
        private readonly OperatorRepository $operators,
        private readonly FailureRecorder $failures,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @param  string|null  $currentPassword  asked for when the operator is signed in; null during the forced first change
     *
     * @throws PasswordRuleBroken|InvalidOperatorCredentials|OperatorLocked|PendingSignInExpired
     */
    public function handle(string $operatorId, string $newPassword, ?string $currentPassword = null): PasswordChangeResult
    {
        $operator = $this->operators->find($operatorId);

        if ($operator === null || ! $operator->canSignIn()) {
            throw new PendingSignInExpired;
        }

        if ($currentPassword !== null) {
            $this->failures->assertOpen($operator->emailLookup);

            if (! Hash::check($currentPassword, $operator->passwordHash)) {
                $this->failures->record($operator->emailLookup, $operator->id, 'password');

                throw new InvalidOperatorCredentials;
            }
        }

        $violations = OperatorPasswordPolicy::violations(
            $newPassword,
            $operator->email,
            matchesCurrent: Hash::check($newPassword, $operator->passwordHash),
        );

        if ($violations !== []) {
            throw new PasswordRuleBroken($violations);
        }

        return DB::transaction(function () use ($operatorId, $newPassword): PasswordChangeResult {
            $locked = $this->operators->lockForUpdate($operatorId);

            if ($locked === null) {
                throw new PendingSignInExpired;
            }

            $state = $locked->isEnrolled() ? OperatorState::Active : OperatorState::AwaitingEnrolment;
            $epoch = $this->operators->changePassword($operatorId, Hash::make($newPassword), $state, bumpEpoch: true);

            $this->audit->passwordChanged($operatorId);

            return new PasswordChangeResult($epoch, $state);
        });
    }
}

<?php

namespace App\Modules\Admin\Application\SignIn;

use App\Modules\Admin\Application\Dto\PasswordAccepted;
use App\Modules\Admin\Domain\Operators\OperatorRecord;
use App\Modules\Admin\Domain\Operators\OperatorState;
use App\Modules\Admin\Domain\Operators\Refusals\InvalidOperatorCredentials;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Operators\SignInStage;
use App\Modules\Admin\Domain\Ports\EmailLookup;
use App\Modules\Admin\Domain\Ports\OperatorRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Step 1: email and password (spec 018, FR-010, FR-013). The lock is checked
 * before the password; an unknown email is checked against a dummy hash, so
 * it takes as long as a wrong password and answers the same.
 */
final class CheckPassword
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly EmailLookup $lookups,
        private readonly OperatorRepository $operators,
        private readonly FailureRecorder $failures,
    ) {}

    /**
     * @throws InvalidOperatorCredentials|OperatorLocked
     */
    public function handle(string $email, string $password): PasswordAccepted
    {
        $lookup = $this->lookups->lookup($email);
        $this->failures->assertOpen($lookup);

        $operator = $this->operators->findByLookup($lookup);
        $usable = $operator !== null && $this->canUsePassword($operator);

        $matches = Hash::check($password, $usable ? $operator->passwordHash : self::dummyHash());

        if (! $usable || ! $matches) {
            $this->failures->record($lookup, $operator?->id, 'password');

            throw new InvalidOperatorCredentials;
        }

        if (Hash::needsRehash($operator->passwordHash)) {
            $this->operators->updatePasswordHash($operator->id, Hash::make($password));
        }

        return new PasswordAccepted($operator->id, $operator->email, $this->nextStage($operator), $operator->credentialEpoch);
    }

    private function canUsePassword(OperatorRecord $operator): bool
    {
        if (! $operator->canSignIn()) {
            return false;
        }

        $expires = $operator->temporaryPasswordExpiresAt;

        return $expires === null || $expires > now();
    }

    private function nextStage(OperatorRecord $operator): SignInStage
    {
        return match (true) {
            $operator->isEnrolled() => SignInStage::SecondFactor,
            $operator->state === OperatorState::AwaitingPasswordChange => SignInStage::PasswordChange,
            default => SignInStage::Enrolment,
        };
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(40));
    }
}

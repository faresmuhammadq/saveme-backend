<?php

namespace App\Modules\Identity\Application\SignIn;

use App\Modules\Identity\Application\Audit\AuditLog;
use App\Modules\Identity\Application\Audit\AuditRecord;
use App\Modules\Identity\Application\Audit\Details\LockoutDetail;
use App\Modules\Identity\Application\Audit\Details\SignInFailedDetail;
use App\Modules\Identity\Domain\Account\UserRecord;
use App\Modules\Identity\Domain\Audit\ActorType;
use App\Modules\Identity\Domain\Audit\AuditEvent;
use App\Modules\Identity\Domain\Audit\SubjectType;
use App\Modules\Identity\Domain\Ports\FailureCounter;
use App\Modules\Identity\Domain\Ports\UserRepository;
use App\Modules\Identity\Domain\Refusals\AccountLocked;
use App\Modules\Identity\Domain\Refusals\InvalidCredentials;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Checks a password against the account lock, the same way everywhere a
 * password is asked for: sign-in, password change, deletion request
 * (security baseline, Lockout; spec 007, R-9).
 *
 * The lock is checked before the password, so a locked account's correct
 * password is still refused and a locked attempt costs no hash. An unknown
 * address is checked against a dummy hash, so it takes as long as a wrong
 * password (Uniform answers).
 */
final class CredentialCheck
{
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly FailureCounter $failures,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return UserRecord the account the password belongs to
     *
     * @throws AccountLocked|InvalidCredentials
     */
    public function verify(string $lookup, string $password): UserRecord
    {
        $lockedFor = $this->failures->lockedFor($lookup);

        if ($lockedFor !== null) {
            throw new AccountLocked($lockedFor);
        }

        $user = $this->users->findByLookup($lookup);
        $usable = $user !== null && ! $user->state->isGone();

        $matches = Hash::check($password, $usable ? $user->passwordHash : self::dummyHash());

        if (! $usable || ! $matches) {
            $this->recordFailure($lookup, $usable ? $user : null);

            throw new InvalidCredentials;
        }

        $this->failures->clear($lookup);

        return $user;
    }

    public function verifyForUser(string $userId, string $password): UserRecord
    {
        return $this->verify($this->users->lookupOf($userId), $password);
    }

    private function recordFailure(string $lookup, ?UserRecord $user): void
    {
        $lockMinutes = $this->failures->recordFailure($lookup);
        $unknownPrefix = $user === null ? substr($lookup, 0, 8) : null;

        $this->audit->record(new AuditRecord(
            AuditEvent::SignInFailed,
            ActorType::System,
            null,
            SubjectType::User,
            $user?->id,
            $user === null ? SignInFailedDetail::forUnknownAddress($lookup) : new SignInFailedDetail,
        ));

        if ($lockMinutes !== null) {
            $this->audit->record(new AuditRecord(
                AuditEvent::LockoutApplied,
                ActorType::System,
                null,
                SubjectType::User,
                $user?->id,
                new LockoutDetail($lockMinutes, $unknownPrefix),
            ));
        }
    }

    /**
     * An argon2id hash at the current parameters, so verifying against it
     * costs what verifying a real password costs.
     */
    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(40));
    }
}

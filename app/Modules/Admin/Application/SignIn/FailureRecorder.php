<?php

namespace App\Modules\Admin\Application\SignIn;

use App\Modules\Admin\Application\Audit\AdminAuditService;
use App\Modules\Admin\Domain\Operators\Refusals\OperatorLocked;
use App\Modules\Admin\Domain\Ports\OperatorFailureCounter;

/**
 * Counts a failed password, code or recovery code against the email's lock
 * and the origin's budget, and writes the entries (spec 018, R-9; FR-050 to
 * FR-053). The lock is checked before the secret is, so a locked attempt
 * costs no hash and a right answer is still refused.
 */
final class FailureRecorder
{
    public function __construct(
        private readonly OperatorFailureCounter $failures,
        private readonly OperatorOriginLimiter $origins,
        private readonly AdminAuditService $audit,
    ) {}

    /**
     * @throws OperatorLocked
     */
    public function assertOpen(string $lookup): void
    {
        $blocked = $this->origins->secondsBlocked();

        if ($blocked !== null) {
            throw new OperatorLocked($blocked);
        }

        $lockedFor = $this->failures->lockedFor($lookup);

        if ($lockedFor !== null) {
            throw new OperatorLocked($lockedFor);
        }
    }

    /**
     * @param  string  $stage  password, second_factor, enrolment or reconfirm
     * @param  string|null  $operatorId  null for an email that is not an operator's
     * @return int|null the lock this failure began, in minutes
     */
    public function record(string $lookup, ?string $operatorId, string $stage): ?int
    {
        $this->origins->hit();
        $lockMinutes = $this->failures->recordFailure($lookup);
        $unknown = $operatorId === null ? $lookup : null;

        $this->audit->signInFailed($operatorId, $stage, $unknown);

        if ($lockMinutes !== null) {
            $this->audit->lockoutApplied($operatorId, $lockMinutes, $unknown);
        }

        return $lockMinutes;
    }

    public function clear(string $lookup): void
    {
        $this->failures->clear($lookup);
    }
}

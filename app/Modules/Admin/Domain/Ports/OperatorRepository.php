<?php

namespace App\Modules\Admin\Domain\Ports;

use App\Modules\Admin\Domain\Operators\OperatorRecord;
use App\Modules\Admin\Domain\Operators\OperatorState;
use DateTimeImmutable;

/**
 * Operator Accounts. Methods that change a row assume the caller holds the
 * row lock where the act needs one (`lockForUpdate`).
 */
interface OperatorRepository
{
    public function find(string $id): ?OperatorRecord;

    public function findByLookup(string $lookup): ?OperatorRecord;

    /**
     * Reads the row with FOR UPDATE; call inside a transaction.
     */
    public function lockForUpdate(string $id): ?OperatorRecord;

    public function create(string $email, string $displayName, string $passwordHash, DateTimeImmutable $temporaryPasswordExpiresAt): OperatorRecord;

    public function countActive(): int;

    /**
     * Active, enrolled operators other than this one: who could approve.
     */
    public function countEligibleApprovers(string $exceptId): int;

    /**
     * Sets a new password. Returns the credential epoch now on the row.
     */
    public function changePassword(string $id, string $passwordHash, OperatorState $state, bool $bumpEpoch): int;

    /**
     * Issues a temporary password: state awaiting_password_change, epoch + 1.
     */
    public function issueTemporaryPassword(string $id, string $passwordHash, DateTimeImmutable $expiresAt): void;

    /**
     * Replaces the hash with one at the current parameters; the password and
     * the epoch are unchanged.
     */
    public function updatePasswordHash(string $id, string $passwordHash): void;

    public function deactivate(string $id): void;

    public function storePendingSecret(string $id, string $secret, DateTimeImmutable $at): void;

    public function clearPendingSecret(string $id): void;

    /**
     * Moves a confirmed secret into place and makes the account active.
     */
    public function enrol(string $id, string $secret, int $codeStep, DateTimeImmutable $at): void;

    /**
     * Swaps the secret of an active account; the state does not change.
     */
    public function replaceSecret(string $id, string $secret, int $codeStep, DateTimeImmutable $at): void;

    public function recordCodeStep(string $id, int $step): void;

    public function markSignedIn(string $id, DateTimeImmutable $at): void;

    /**
     * Completes a re-enrolment: secret, enrolment, code step cleared; state
     * awaiting_enrolment; epoch + 1.
     */
    public function clearFactor(string $id): void;
}

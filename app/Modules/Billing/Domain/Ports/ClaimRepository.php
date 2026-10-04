<?php

namespace App\Modules\Billing\Domain\Ports;

use App\Modules\Billing\Domain\Claims\ClaimRecord;
use App\Modules\Billing\Domain\Claims\NewClaim;
use App\Modules\Billing\Domain\Claims\QueuedClaim;
use App\Modules\Billing\Domain\Claims\RejectReason;
use App\Modules\Billing\Domain\Refusals\ReferenceAlreadyApproved;
use DateTimeImmutable;

interface ClaimRepository
{
    public function insert(NewClaim $claim): ClaimRecord;

    public function find(string $id): ?ClaimRecord;

    /**
     * Reads the claim holding a row lock until the transaction ends.
     */
    public function lockForUpdate(string $id): ?ClaimRecord;

    /**
     * Other claims carrying the same normalised reference.
     *
     * @return list<ClaimRecord>
     */
    public function duplicatesOf(string $reference, ?string $exceptId = null): array;

    /**
     * Marks a submitted claim approved.
     *
     * @throws ReferenceAlreadyApproved when another claim with the reference is approved
     */
    public function markApproved(string $id, string $operatorId, DateTimeImmutable $now): void;

    public function markRejected(string $id, string $operatorId, RejectReason $reason, ?string $note, DateTimeImmutable $now): void;

    /**
     * Sets `closed_at` on a claim not yet closed.
     */
    public function close(string $id, DateTimeImmutable $now): void;

    /**
     * Submitted claims first, oldest first, then the rest; cursor is the last id seen.
     *
     * @return list<QueuedClaim>
     */
    public function queue(?string $afterId, int $limit): array;

    /**
     * Expires submitted claims past `expires_at`, in one conditional update.
     *
     * @return list<string> ids of the claims this call expired
     */
    public function expireDue(DateTimeImmutable $now, int $limit): array;

    /**
     * Claims closed at or before the cutoff whose personal data is not yet erased.
     *
     * @return list<ClaimRecord>
     */
    public function erasableClosedBefore(DateTimeImmutable $cutoff, int $limit): array;

    /**
     * Nulls the address, lookup value and receipt columns and stamps `erased_at`.
     */
    public function markErased(string $id, DateTimeImmutable $now): void;

    /**
     * Deletes claims (and their codes) closed at or before the cutoff.
     *
     * @return int claims deleted
     */
    public function deleteClosedBefore(DateTimeImmutable $cutoff): int;
}

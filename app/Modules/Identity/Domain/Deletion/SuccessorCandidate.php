<?php

namespace App\Modules\Identity\Domain\Deletion;

use DateTimeImmutable;

/**
 * An active member other than the departing person, as the successor rule
 * sees them. `joinedAt` is the creation instant of the current membership
 * record: a member who left and re-joined starts again.
 */
final readonly class SuccessorCandidate
{
    /**
     * @param  'editor'|'viewer'  $role
     */
    public function __construct(
        public string $userId,
        public string $role,
        public DateTimeImmutable $joinedAt,
        public bool $accountPending,
        public string $membershipId = '',
    ) {}
}

<?php

namespace App\Modules\Identity\Application\Deletion;

use App\Modules\Identity\Domain\Deletion\Successor;
use DateTimeImmutable;

/**
 * What a purge step may ask about the departure: when it runs, and who
 * inherits each shared wallet the person owns, decided by the same rule the
 * deletion preview uses (FR-033).
 */
final class PurgeContext
{
    public function __construct(
        public readonly string $userId,
        public readonly DateTimeImmutable $now,
        private readonly DeletionPlanner $planner,
    ) {}

    /**
     * The member who becomes owner of the wallet, judged now; null when
     * nobody else belongs and the wallet goes with the account.
     */
    public function successorFor(string $walletId): ?Successor
    {
        return $this->planner->successorFor($this->userId, $walletId);
    }
}

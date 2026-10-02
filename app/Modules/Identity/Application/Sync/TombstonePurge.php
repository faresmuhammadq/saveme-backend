<?php

namespace App\Modules\Identity\Application\Sync;

use App\Modules\Identity\Domain\Ports\UserScope;
use DateTimeImmutable;

/**
 * Identity's side of the daily tombstone purge (spec 013, R-11). No Identity
 * record is tombstoned by any current flow (a Profile is never deleted, a
 * Device Session ends but stays), so there is nothing to remove yet; the
 * service exists so the purge run has one owner per module and so other
 * modules can raise a user scope's horizon when they remove a tombstone from
 * it (Wallets: a person's category, a membership).
 */
final class TombstonePurge
{
    public function __construct(private readonly UserScope $scope) {}

    /**
     * @return array<string, int> rows removed per record type
     */
    public function purge(DateTimeImmutable $before, int $batchSize = 500): array
    {
        return [];
    }

    /**
     * Raises the person's purge horizon to $position (never lowers it), under
     * the user row lock. Callers hold their wallet locks first and call this
     * with several people in ascending user id (ADR-0017 rule 5).
     */
    public function raiseHorizon(string $userId, int $position): void
    {
        $this->scope->raiseHorizon($userId, $position);
    }
}

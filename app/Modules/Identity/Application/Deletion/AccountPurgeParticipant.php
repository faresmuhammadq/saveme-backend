<?php

namespace App\Modules\Identity\Application\Deletion;

use App\Modules\Identity\Domain\Deletion\PurgeCounts;
use App\Modules\Identity\Domain\Deletion\PurgeStep;

/**
 * One module's step of an account purge. Identity orders the steps; each
 * module implements its own in `Application/Departure/` and tags it
 * `identity.purge-participants` (P-02). A step acts through the module's
 * existing services as the departing person while their role is valid, and
 * is a no-op on state it already finished: a purge may run it again after a
 * crash between its commit and its record.
 */
interface AccountPurgeParticipant
{
    public function step(): PurgeStep;

    public function purge(string $userId, PurgeContext $context): PurgeCounts;
}

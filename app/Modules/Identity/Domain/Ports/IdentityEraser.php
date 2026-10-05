<?php

namespace App\Modules\Identity\Domain\Ports;

use DateTimeImmutable;

/**
 * Removes what an account's identity rests on, as the last step of a purge
 * (spec 022, FR-049). Runs inside the purge's transaction.
 */
interface IdentityEraser
{
    /**
     * Tombstones the profile from the person's user counter, then deletes it
     * and raises the user scope's purge horizon past that stamp: no reader of
     * the scope remains (ADR-0024).
     */
    public function eraseProfile(string $userId, DateTimeImmutable $at): void;

    /**
     * Deletes the budget start days, the verification and reset links, every
     * device session row, the framework web sessions and the sign-in failure
     * counter keyed by the lookup value, then the person's data key: after
     * this nothing sealed under it can be read, backups included.
     */
    public function eraseDependants(string $userId, string $lookup): void;
}

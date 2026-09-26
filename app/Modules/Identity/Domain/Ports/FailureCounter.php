<?php

namespace App\Modules\Identity\Domain\Ports;

/**
 * Consecutive sign-in failures per address lookup value (security baseline,
 * Lockout). Keyed by lookup value, so an unregistered address counts and
 * locks exactly like a registered one.
 */
interface FailureCounter
{
    /**
     * @return int|null seconds the lock has left, or null when not locked
     */
    public function lockedFor(string $lookup): ?int;

    /**
     * @return int|null the lock applied by this failure, in minutes, if any
     */
    public function recordFailure(string $lookup): ?int;

    public function clear(string $lookup): void;
}

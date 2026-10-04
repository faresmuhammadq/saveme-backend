<?php

namespace App\Modules\Admin\Domain\Ports;

/**
 * Consecutive operator sign-in failures per email lookup value, separate from
 * the application's (FR-052). An unregistered email counts and locks alike.
 */
interface OperatorFailureCounter
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

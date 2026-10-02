<?php

namespace App\Modules\Identity\Domain\Ports;

/**
 * The user scope counter (spec 013, R-5; ADR-0008 rules 2-3, ADR-0017 rule 6).
 * The counter is the person's `users` row: next() increments it and returns
 * the new value, and the row lock it takes is held until the caller's
 * transaction ends, so values are commit-ordered and never shared.
 */
interface ChangeSequence
{
    public function next(string $userId): int;
}

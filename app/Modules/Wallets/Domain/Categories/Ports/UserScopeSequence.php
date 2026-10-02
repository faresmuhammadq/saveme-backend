<?php

namespace App\Modules\Wallets\Domain\Categories\Ports;

/**
 * The user scope's change counter (spec 013, R-5): a person's own categories
 * and their memberships stamp from the counter on the person's `users` row,
 * owned by Identity. next() takes that row's lock until the caller's
 * transaction ends, so callers lock wallets first and take this stamp last,
 * several people in ascending user id (ADR-0017 rule 5).
 */
interface UserScopeSequence
{
    public function next(string $userId): int;
}

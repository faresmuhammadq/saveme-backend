<?php

namespace App\Modules\Identity\Application\Sync;

use App\Modules\Identity\Domain\Ports\ChangeSequence;

/**
 * Identity's published way to stamp a record of a person's user scope (spec
 * 013, R-5). Other modules (Wallets: categories, memberships) call it instead
 * of touching the counter. LOCK ORDER: callers lock their wallets first and
 * take this stamp last, several people in ascending user id (ADR-0017 rule 5);
 * the row lock it takes lasts until the caller's transaction ends.
 */
final class UserScopeCounter
{
    public function __construct(private readonly ChangeSequence $sequence) {}

    public function next(string $userId): int
    {
        return $this->sequence->next($userId);
    }
}

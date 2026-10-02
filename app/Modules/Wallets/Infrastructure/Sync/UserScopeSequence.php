<?php

namespace App\Modules\Wallets\Infrastructure\Sync;

use App\Modules\Identity\Application\Sync\UserScopeCounter;
use App\Modules\Wallets\Domain\Categories\Ports\UserScopeSequence as UserScopeSequencePort;

/**
 * The user scope's counter is Identity's (spec 013, R-5): this adapter calls
 * Identity's Application service rather than its Infrastructure (P-02).
 */
final class UserScopeSequence implements UserScopeSequencePort
{
    public function __construct(private readonly UserScopeCounter $counter) {}

    public function next(string $userId): int
    {
        return $this->counter->next($userId);
    }
}

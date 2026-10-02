<?php

namespace App\Modules\Wallets\Domain\Categories\Ports;

/**
 * The user scope's change counter (spec 007 R-19): one shared PostgreSQL
 * sequence orders every user-scoped resource until B14 takes over commit
 * ordering.
 */
interface UserScopeSequence
{
    public function next(): int;
}

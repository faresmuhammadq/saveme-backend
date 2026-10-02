<?php

namespace App\Modules\Wallets\Infrastructure\Sync;

use App\Modules\Wallets\Domain\Categories\Ports\UserScopeSequence as UserScopeSequencePort;
use Illuminate\Support\Facades\DB;

/**
 * The user scope's counter is the shared sync_change_seq sequence (spec 007
 * R-19), which Profile and Device Session already draw from. Wallets keeps
 * its own adapter rather than importing Identity's (P-02).
 */
final class UserScopeSequence implements UserScopeSequencePort
{
    public function next(): int
    {
        return (int) DB::scalar("select nextval('sync_change_seq')");
    }
}

<?php

namespace App\Modules\Wallets\Infrastructure\Sync;

use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The wallet row is the scope counter (ADR-0008 rule 2). The UPDATE takes
 * the row lock itself, so a call made without it still never hands out a
 * value twice; the application takes the lock first so the value order is
 * the commit order.
 */
final class WalletScopeSequence implements ScopeSequence
{
    public function nextFor(string $walletId): int
    {
        $row = DB::selectOne('UPDATE wallets SET change_seq = change_seq + 1 WHERE id = ? RETURNING change_seq', [$walletId]);

        if ($row === null) {
            throw new RuntimeException('No wallet to count changes for.');
        }

        return (int) $row->change_seq;
    }
}

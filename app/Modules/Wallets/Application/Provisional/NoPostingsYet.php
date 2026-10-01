<?php

namespace App\Modules\Wallets\Application\Provisional;

use App\Modules\Wallets\Domain\Ports\PostingTotals;

/**
 * Stand-in until the ledger exists: no wallet has a posting yet.
 *
 * Replaced by B6; delete this directory then.
 */
final class NoPostingsYet implements PostingTotals
{
    public function sumFor(string $walletId): int
    {
        return 0;
    }

    public function hasAny(string $walletId): bool
    {
        return false;
    }
}

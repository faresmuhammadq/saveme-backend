<?php

namespace App\Modules\Wallets\Application\Provisional;

use App\Modules\Wallets\Application\TransactionQueryService;

/**
 * Stand-in until the ledger exists: nobody has recorded a transaction yet.
 *
 * Replaced by B6; delete this directory then.
 */
final class NoTransactionsYet implements TransactionQueryService
{
    public function hasLiveTransactionsRecordedBy(string $userId): bool
    {
        return false;
    }
}

<?php

namespace App\Modules\Wallets\Application;

/**
 * What other modules may ask Wallets & Ledger about transactions. B2 needs one
 * question; B6 implements it over the ledger and adds the rest.
 */
interface TransactionQueryService
{
    /**
     * Whether the person has recorded at least one live transaction
     * (created_by = them, deleted_at null) in any wallet. Locks their default
     * currency (spec 008, R-3).
     */
    public function hasLiveTransactionsRecordedBy(string $userId): bool;
}

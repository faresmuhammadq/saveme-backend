<?php

namespace App\Modules\Wallets\Domain\Ports;

/**
 * What the ledger says about a wallet's postings. Until B6 builds postings it
 * is bound to a stand-in that answers zero and false; B6 replaces it.
 */
interface PostingTotals
{
    /**
     * The sum of the wallet's postings, in minor units of its currency.
     */
    public function sumFor(string $walletId): int;

    /**
     * Whether any posting references the wallet. Locks its currency.
     */
    public function hasAny(string $walletId): bool;
}

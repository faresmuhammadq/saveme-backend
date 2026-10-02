<?php

namespace App\Modules\Wallets\Domain\Ports;

/**
 * What the ledger says about a wallet's postings (spec 012, R-3).
 */
interface PostingTotals
{
    /**
     * The sum of the wallet's postings, in minor units of its currency.
     */
    public function sumFor(string $walletId): int;

    /**
     * Whether any transaction, live or tombstoned, posts or posted to the
     * wallet. Locks its currency.
     */
    public function hasAny(string $walletId): bool;

    /**
     * Which of the wallets any transaction posts or posted to, answered with
     * one query for the whole set (a page of wallets, SC-006).
     *
     * @param  list<string>  $walletIds
     * @return list<string> the locked ids
     */
    public function lockedAmong(array $walletIds): array;
}

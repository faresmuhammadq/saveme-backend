<?php

namespace App\Modules\Wallets\Domain\Transactions\Ports;

use App\Modules\Wallets\Domain\Transactions\PostingLine;

interface PostingRepository
{
    /**
     * Replaces the transaction's postings wholesale (ADR-0012 rule 8).
     *
     * @param  list<PostingLine>  $lines
     */
    public function replaceFor(string $transactionId, array $lines): void;

    /**
     * Removes the transaction's postings: a tombstone posts nothing
     * (ADR-0012 rule 9).
     */
    public function removeFor(string $transactionId): void;
}

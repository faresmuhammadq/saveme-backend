<?php

namespace App\Modules\Wallets\Application;

use App\Modules\Wallets\Domain\Transactions\Ports\TransactionRepository;

/**
 * Lets another module ask whether an identifier already names a Wallets &
 * Ledger record (a wallet, category, reference, membership, transaction,
 * rule or occurrence), so a client id is never reused across kinds (P-06
 * rule 3) without that module knowing Wallets' tables.
 */
final class RecordIdentifiers
{
    public function __construct(private readonly TransactionRepository $transactions) {}

    public function isTaken(string $id): bool
    {
        return $this->transactions->identifierTaken($id);
    }
}

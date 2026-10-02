<?php

namespace App\Modules\Wallets\Application\Transactions\Dto;

/**
 * The outcome of a transaction save: the transaction as a read presents it,
 * and whether this call created it (201) or not (200).
 */
final readonly class SavedTransaction
{
    public function __construct(
        public TransactionView $view,
        public bool $created,
    ) {}
}

<?php

namespace App\Modules\Wallets\Application\Transactions\Dto;

use App\Modules\Wallets\Domain\Transactions\TransactionRecord;

/**
 * A transaction as a read presents it.
 */
final readonly class TransactionView
{
    public function __construct(public TransactionRecord $transaction) {}
}

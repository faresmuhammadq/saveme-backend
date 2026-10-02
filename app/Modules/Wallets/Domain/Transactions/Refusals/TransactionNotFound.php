<?php

namespace App\Modules\Wallets\Domain\Transactions\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The transaction is not one the caller may know of: another wallet's, another
 * kind of record, unknown, or the wallet is not theirs (P-06 rule 3, P-08
 * rule 3). Never a hint it exists.
 */
final class TransactionNotFound extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::NotFound);
    }
}

<?php

namespace App\Modules\Wallets\Domain\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * The caller is not a member of the wallet, or the identifier is another
 * person's (P-06 rule 3, P-08 rule 3): not-found, never a hint it exists.
 */
final class WalletNotFound extends Refusal
{
    public function __construct()
    {
        parent::__construct(ErrorCode::NotFound);
    }
}

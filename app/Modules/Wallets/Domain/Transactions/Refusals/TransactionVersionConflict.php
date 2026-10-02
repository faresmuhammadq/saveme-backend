<?php

namespace App\Modules\Wallets\Domain\Transactions\Refusals;

use App\Modules\Shared\Domain\Errors\ErrorCode;
use App\Modules\Shared\Domain\Errors\Refusal;

/**
 * A write carried a stale version, or targeted a tombstone. Nothing was
 * applied; `current` carries the stored transaction as a read renders it so
 * the client merges without a second request (P-07 rules 2-4).
 */
final class TransactionVersionConflict extends Refusal
{
    /**
     * @param  array<string, mixed>  $current  the transaction as a read returns it
     */
    public function __construct(array $current)
    {
        parent::__construct(ErrorCode::VersionConflict, extensions: ['current' => $current]);
    }
}

<?php

namespace App\Modules\Wallets\Application;

use App\Modules\Wallets\Domain\Ports\ScopeSequence;
use App\Modules\Wallets\Domain\Wallets\WalletRecord;

/**
 * One locked write in a wallet's scope: hands out the next `change_seq` of
 * the wallet's counter to every record the write touches (ADR-0008 rules
 * 3-5). The wallet takes the last value when the scope closes.
 */
final class ScopeWrite
{
    private bool $stamped = false;

    public function __construct(
        public readonly WalletRecord $wallet,
        private readonly ScopeSequence $sequence,
    ) {}

    public function nextChangeSeq(): int
    {
        $this->stamped = true;

        return $this->sequence->nextFor($this->wallet->id);
    }

    public function hasStamped(): bool
    {
        return $this->stamped;
    }
}

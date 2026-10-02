<?php

namespace App\Modules\Wallets\Domain\Recurring\Ports;

/**
 * Hands out several consecutive values of a wallet scope's change counter at
 * once (ADR-0008 rules 2-5), for a write that stamps many rows in one
 * statement. As ScopeSequence::nextFor(), it must be called with the wallet
 * row already locked, so the block is commit-ordered and never shared.
 */
interface StampBlock
{
    /**
     * Reserves $count values and returns the first; the block is the
     * returned value up to returned + $count - 1.
     */
    public function reserve(string $walletId, int $count): int;
}
